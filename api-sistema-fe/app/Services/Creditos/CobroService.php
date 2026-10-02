<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Models\Cash\CashSession;
use App\Enums\Creditos\PagoEstado;
use App\Enums\Creditos\TipoCorrelativo;
use App\Enums\Creditos\TipoMovimientoSaldoFavor;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoConfiguracion;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\Dto\CotizacionPago;
use App\Services\Creditos\Dto\CreditoCargado;
use App\Services\Creditos\Dto\SaldoCredito;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\Dto\Aplicacion;
use App\Services\Creditos\Motor\Dto\PagoAAplicar;
use App\Services\Creditos\Motor\Dto\ResultadoAplicacion;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Fecha;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Cobros (00 1.4-1.6, 1.12). El reparto lo decide el motor sobre TODOS los pagos válidos más
 * el nuevo, así un pago retroactivo reordena a los posteriores sin lógica aparte.
 */
class CobroService
{
    /** Referencia temporal del pago nuevo dentro del motor, antes de tener id. */
    private const NUEVO = 'nuevo';
    private const ESTADOS_COBRABLES = [CreditoEstado::Activo, CreditoEstado::Castigado];
    private const PERMISO_RETROACTIVO = 'creditos.pago_fecha_anterior';

    public function __construct(
        private readonly CargadorCredito $cargador,
        private readonly PersistidorReparto $persistidor,
        private readonly AplicadorPagos $aplicador,
        private readonly CajaCredito $caja,
        private readonly SaldoAFavorService $saldos,
        private readonly CorrelativoCreditoService $correlativos,
        private readonly AlcanceCartera $alcance,
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    public function cotizar(Credito $credito, int $monto, DestinoExcedente $destino, User $usuario): CotizacionPago
    {
        $this->validarCobrable($credito, $usuario);
        $hoy = $this->reloj->hoy();
        $carga = $this->cargador->cargar($credito);
        $resultado = $this->aplicarNuevo($carga, $hoy, $monto, OrigenPago::Cobro, $destino, $hoy);
        $pago = $resultado->pago(self::NUEVO);

        return new CotizacionPago(
            $this->aplicador->montoExigible($carga->estado, $carga->pagos, $hoy),
            $pago->montoAplicado,
            $pago->montoExcedente,
            array_values(array_filter($resultado->aplicaciones, static fn (Aplicacion $a): bool => $a->referenciaPago === self::NUEVO)),
            $resultado->finalizado,
            SaldoCredito::calcular($carga->estado, $resultado, $hoy),
        );
    }

    public function cobrar(Credito $credito, SolicitudCobro $solicitud, User $usuario): CreditoPago
    {
        return DB::transaction(fn (): CreditoPago => $this->ejecutarCobro($credito, $solicitud, $usuario));
    }

    private function ejecutarCobro(Credito $credito, SolicitudCobro $s, User $usuario): CreditoPago
    {
        $credito = Credito::whereKey($credito->id)->lockForUpdate()->firstOrFail();
        $this->validarCobrable($credito, $usuario);

        $hoy = $this->reloj->hoy();
        $fechaPago = $this->fechaDelPago($s, $hoy, $usuario);
        $origen = $s->usarSaldoAFavor ? OrigenPago::SaldoAFavor : OrigenPago::Cobro;
        if ($s->usarSaldoAFavor) {
            // 04c.1: otro cobro del mismo cliente (en otro crédito) no puede gastar el mismo saldo.
            $this->saldos->bloquear($credito->cliente_id);
            if ($this->saldos->saldo($credito->cliente_id) < $s->montoRecibido) {
                throw new HttpException(422, 'El cliente no tiene saldo a favor suficiente.');
            }
        }
        // Con saldo a favor no entra dinero: lo que no se aplique sigue siendo saldo del cliente.
        $destino = $s->usarSaldoAFavor ? DestinoExcedente::SaldoAFavor : $s->destinoExcedente;
        $sesion = $s->usarSaldoAFavor ? null : $this->caja->sesionAbierta($usuario);
        if (! $s->usarSaldoAFavor && $s->paymentMethodId === null) {
            throw new HttpException(422, 'Indica el método de pago.');
        }

        $carga = $this->cargador->cargar($credito);
        $resultado = $this->aplicarNuevo($carga, $fechaPago, $s->montoRecibido, $origen, $destino, $hoy);
        $reparto = $resultado->pago(self::NUEVO);
        if ($reparto->montoAplicado === 0) {
            throw new HttpException(422, 'El crédito no tiene deuda pendiente para aplicar este pago.');
        }

        $pago = CreditoPago::create([
            'credito_id' => $credito->id,
            'numero_recibo' => $this->correlativos->siguiente(TipoCorrelativo::Recibo),
            'monto_recibido' => Dinero::aSoles($s->usarSaldoAFavor ? $reparto->montoAplicado : $s->montoRecibido),
            'monto_aplicado' => Dinero::aSoles($reparto->montoAplicado),
            'monto_excedente' => Dinero::aSoles($s->usarSaldoAFavor ? 0 : $reparto->montoExcedente),
            'destino_excedente' => ! $s->usarSaldoAFavor && $reparto->montoExcedente > 0 ? $this->destinoFinal($destino) : null,
            'fecha_pago' => $this->momentoDelPago($fechaPago),
            'origen' => $origen,
            'es_cierre' => $reparto->fueLiquidacion,
            'pagado_por_cliente_id' => $s->pagadoPorClienteId ?? $credito->cliente_id,
            'payment_method_id' => $s->usarSaldoAFavor ? null : $s->paymentMethodId,
            'referencia' => $s->referencia,
            'observaciones' => $s->observaciones,
            'clave_idempotencia' => $s->clave,
            'estado' => PagoEstado::Valido,
            'registrado_por' => $usuario->id,
        ]);

        $this->registrarDinero($credito, $pago, $s, $reparto->montoAplicado, $reparto->montoExcedente, $destino, $sesion, $usuario);
        $this->persistidor->persistir($credito, $carga, $resultado, [self::NUEVO => $pago->id]);

        if ($s->fechaPago !== null && ! $s->fechaPago->esIgualA($hoy)) {
            $this->auditoria->registrar('pago.retroactivo', $pago, $credito->id, null, ['fecha_pago' => $fechaPago->aTexto()], $s->motivoRetroactivo, $usuario);
        }

        return $pago->refresh();
    }

    private function aplicarNuevo(CreditoCargado $carga, Fecha $fechaPago, int $monto, OrigenPago $origen, DestinoExcedente $destino, Fecha $hoy): ResultadoAplicacion
    {
        $nuevo = new PagoAAplicar(self::NUEVO, $fechaPago, PHP_INT_MAX, $monto, $origen, $destino);

        return $this->aplicador->aplicar($carga->estado, [...$carga->pagos, $nuevo], $hoy);
    }

    /** Caja: entra lo recibido; la devolución del excedente es una salida aparte (00 1.4). */
    private function registrarDinero(
        Credito $credito,
        CreditoPago $pago,
        SolicitudCobro $s,
        int $aplicado,
        int $excedente,
        DestinoExcedente $destino,
        ?CashSession $sesion,
        User $usuario,
    ): void {
        if ($s->usarSaldoAFavor) {
            $this->saldos->registrar($credito->cliente_id, TipoMovimientoSaldoFavor::Uso, -$aplicado, $pago->id, "Pago {$pago->numero_recibo}", $usuario);

            return;
        }

        $entrada = $this->caja->entrada($sesion, $usuario, CajaCredito::PAGO, $pago->id, $s->montoRecibido, $s->paymentMethodId, $credito->cliente, "Cobro {$pago->numero_recibo} crédito {$credito->numero_credito}");
        $pago->update(['cash_movement_id' => $entrada->id]);

        if ($excedente === 0) {
            return;
        }
        if ($destino === DestinoExcedente::SaldoAFavor) {
            $this->saldos->registrar($credito->cliente_id, TipoMovimientoSaldoFavor::Abono, $excedente, $pago->id, "Excedente del pago {$pago->numero_recibo}", $usuario);

            return;
        }
        $this->caja->salida($sesion, $usuario, CajaCredito::DEVOLUCION_EXCEDENTE, $pago->id, $excedente, $s->paymentMethodId, $credito->cliente, "Devolución de excedente {$pago->numero_recibo}");
    }

    /** Con destino adelanto solo queda excedente si ya no hay cuotas: se devuelve. */
    private function destinoFinal(DestinoExcedente $destino): DestinoExcedente
    {
        return $destino === DestinoExcedente::Adelanto ? DestinoExcedente::Devolver : $destino;
    }

    private function fechaDelPago(SolicitudCobro $s, Fecha $hoy, User $usuario): Fecha
    {
        if ($s->fechaPago === null || $s->fechaPago->esIgualA($hoy)) {
            return $hoy;
        }
        if ($s->fechaPago->esPosteriorA($hoy)) {
            throw new HttpException(422, 'La fecha del pago no puede ser futura.');
        }
        if (! $usuario->can(self::PERMISO_RETROACTIVO)) {
            throw new HttpException(403, 'Registrar un pago con fecha anterior requiere el permiso creditos.pago_fecha_anterior.');
        }
        if (trim((string) $s->motivoRetroactivo) === '') {
            throw new HttpException(422, 'Indica el motivo del pago con fecha anterior.');
        }
        $maximo = CreditoConfiguracion::actual()->dias_max_pago_retroactivo;
        if ($s->fechaPago->diasHasta($hoy) > $maximo) {
            throw new HttpException(422, "Solo se pueden registrar pagos de hasta {$maximo} días atrás.");
        }

        return $s->fechaPago;
    }

    /** fecha_pago guarda la hora de Lima; para un retroactivo, la fecha real con la hora del registro. */
    private function momentoDelPago(Fecha $fecha): string
    {
        return $fecha->aTexto() . ' ' . $this->reloj->ahora()->format('H:i:s');
    }

    private function validarCobrable(Credito $credito, User $usuario): void
    {
        $this->alcance->asegurar($credito, $usuario);
        if (! in_array($credito->estado, self::ESTADOS_COBRABLES, true)) {
            throw new HttpException(422, 'Este crédito no admite cobros en su estado actual.');
        }
    }
}
