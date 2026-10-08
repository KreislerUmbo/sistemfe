<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\OrigenRegistro;
use App\Enums\Creditos\PagoEstado;
use App\Enums\Creditos\TipoCorrelativo;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoAutorizacion;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\Dto\DatosCredito;
use App\Services\Creditos\Dto\PreviewRenovacion;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\Dto\Infraccion;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Renovación (00 1.12): la liquidación del crédito actual se descuenta del nuevo. El anterior
 * se cierra con un pago "renovacion" sin caja y en caja solo sale la entrega neta.
 */
class RenovacionService
{
    private const ESTADOS_RENOVABLES = [CreditoEstado::Activo, CreditoEstado::Castigado];
    private const PERMISO_AUTORIZAR = 'creditos.autorizar_excepcion';

    public function __construct(
        private readonly CreditoBorradorService $borradores,
        private readonly EscritorCronograma $escritor,
        private readonly LiquidacionService $liquidaciones,
        private readonly LimitesService $limites,
        private readonly CargadorCredito $cargador,
        private readonly PersistidorReparto $persistidor,
        private readonly AplicadorPagos $aplicador,
        private readonly CajaCredito $caja,
        private readonly CorrelativoCreditoService $correlativos,
        private readonly AlcanceCartera $alcance,
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    public function preview(Credito $anterior, DatosCredito $datos, User $usuario): PreviewRenovacion
    {
        $this->validar($anterior, $usuario);
        $datos = $datos->conCliente($anterior->cliente_id);
        $liquidacion = $this->liquidaciones->calcular($anterior, $this->reloj->hoy());

        return new PreviewRenovacion(
            $liquidacion,
            $datos->montoCapital,
            $datos->montoCapital - $liquidacion->montoLiquidacion,
            $this->borradores->preview($datos),
            $this->limites->evaluar($anterior->cliente_id, $datos->montoCapital, $anterior->id),
        );
    }

    public function renovar(Credito $anterior, DatosCredito $datos, ?string $motivoAutorizacion, User $usuario): Credito
    {
        return DB::transaction(fn (): Credito => $this->ejecutar($anterior, $datos, $motivoAutorizacion, $usuario));
    }

    private function ejecutar(Credito $anterior, DatosCredito $datos, ?string $motivoAutorizacion, User $usuario): Credito
    {
        $anterior = Credito::whereKey($anterior->id)->lockForUpdate()->firstOrFail();
        $this->validar($anterior, $usuario);
        $hoy = $this->reloj->hoy();
        $datos = $datos->conCliente($anterior->cliente_id);
        if (! $datos->fechaDesembolso->esIgualA($hoy)) {
            throw new HttpException(422, 'La fecha de desembolso de la renovación debe ser hoy.');
        }

        $liquidacion = $this->liquidaciones->calcular($anterior, $hoy);
        // 08-oct-2026 (1.21 ampliada): neto > 0 se entrega, 0 sin dinero, < 0 el cliente paga la
        // diferencia (caso típico: paga el interés y renueva por el mismo capital). Todo en una sola
        // operación de cierre: si el pago del cliente fuera un cobro aparte, en una renovación
        // anticipada el interés adelantado no se descontaría (1.5) y pagaría de más.
        $neto = $datos->montoCapital - $liquidacion->montoLiquidacion;
        $autorizar = $this->bloqueosAAutorizar($anterior, $datos, $motivoAutorizacion, $usuario);
        $sesion = null;
        if ($neto !== 0) {
            if ($datos->paymentMethodId === null) {
                throw new HttpException(422, $neto > 0 ? 'Indica el método con el que se entrega el dinero.' : 'Indica el método con el que paga el cliente.');
            }
            $sesion = $this->caja->sesionAbierta($usuario);
        }

        $cronograma = $this->borradores->preview($datos);
        $nuevo = Credito::create([
            ...$this->borradores->atributos($datos, $cronograma),
            'numero_credito' => $this->correlativos->siguiente(TipoCorrelativo::Credito),
            'estado' => CreditoEstado::Activo,
            'origen_registro' => OrigenRegistro::Renovacion,
            'version_cronograma_actual' => 1,
            'credito_renovado_id' => $anterior->id,
            'registrado_por' => $usuario->id,
            'asesor_id' => $this->borradores->asesorPara($anterior->cliente_id, $usuario),
        ]);
        $this->escritor->guardar($nuevo, $cronograma, 1);
        foreach ($autorizar as $infraccion) {
            CreditoAutorizacion::create([
                'credito_id' => $nuevo->id, 'regla' => $infraccion->regla, 'detalle' => $infraccion->detalle,
                'motivo' => $motivoAutorizacion, 'autorizado_por' => $usuario->id,
            ]);
        }

        // El anterior se cancela con la liquidación: la parte que cubre el crédito nuevo no pasa por
        // caja (00 §3); si el cliente paga la diferencia, esa parte entra a caja con este mismo pago.
        $pagoRenovacion = CreditoPago::create([
            'credito_id' => $anterior->id,
            'numero_recibo' => $this->correlativos->siguiente(TipoCorrelativo::Recibo),
            'monto_recibido' => Dinero::aSoles($liquidacion->montoLiquidacion),
            'monto_aplicado' => Dinero::aSoles($liquidacion->montoLiquidacion),
            'fecha_pago' => $this->reloj->ahora()->format('Y-m-d H:i:s'),
            'origen' => OrigenPago::Renovacion,
            'es_cierre' => true,
            'pagado_por_cliente_id' => $anterior->cliente_id,
            'observaciones' => "Cancelado por la renovación {$nuevo->numero_credito}",
            'clave_idempotencia' => (string) Str::uuid(),
            'estado' => PagoEstado::Valido,
            'registrado_por' => $usuario->id,
        ]);
        $carga = $this->cargador->cargar($anterior);
        $this->persistidor->persistir($anterior, $carga, $this->aplicador->aplicar($carga->estado, $carga->pagos, $hoy));

        if ($neto > 0) {
            $movimiento = $this->caja->salida($sesion, $usuario, CajaCredito::DESEMBOLSO, $nuevo->id, $neto, $datos->paymentMethodId, $anterior->cliente, "Desembolso neto por renovación de {$anterior->numero_credito}");
            $nuevo->update(['cash_movement_id' => $movimiento->id]);
        } elseif ($neto < 0) {
            $entrada = $this->caja->entrada($sesion, $usuario, CajaCredito::PAGO, $pagoRenovacion->id, -$neto, $datos->paymentMethodId, $anterior->cliente, "Pago del cliente al renovar {$anterior->numero_credito} ({$nuevo->numero_credito})");
            $pagoRenovacion->update(['payment_method_id' => $datos->paymentMethodId, 'cash_movement_id' => $entrada->id]);
        }

        $this->auditoria->registrar('credito.renovar', $nuevo, $nuevo->id, null, [
            'credito_anterior' => $anterior->numero_credito,
            'liquidacion' => Dinero::aSoles($liquidacion->montoLiquidacion),
            'entrega_neta' => Dinero::aSoles(max(0, $neto)),
            'pago_del_cliente' => Dinero::aSoles(max(0, -$neto)),
        ], $motivoAutorizacion, $usuario);

        return $nuevo->refresh();
    }

    /** @return list<Infraccion> bloqueos que se autorizan en el mismo acto (con permiso y motivo) */
    private function bloqueosAAutorizar(Credito $anterior, DatosCredito $datos, ?string $motivo, User $usuario): array
    {
        $bloqueos = $this->limites->evaluarParaOtorgar($anterior->cliente_id, $datos->montoCapital, $anterior->id)->bloqueos();
        if ($bloqueos === []) {
            return [];
        }
        $autorizables = array_filter($bloqueos, static fn (Infraccion $i): bool => $i->autorizable);
        if (count($autorizables) !== count($bloqueos) || trim((string) $motivo) === '' || ! $usuario->can(self::PERMISO_AUTORIZAR)) {
            throw new LimitesExcedidos($bloqueos);
        }

        return $bloqueos;
    }

    private function validar(Credito $anterior, User $usuario): void
    {
        $this->alcance->asegurar($anterior, $usuario);
        if (! in_array($anterior->estado, self::ESTADOS_RENOVABLES, true)) {
            throw new HttpException(422, 'Solo se renueva un crédito activo o castigado.');
        }
    }
}
