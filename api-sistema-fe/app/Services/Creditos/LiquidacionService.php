<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\PagoEstado;
use App\Enums\Creditos\TipoCorrelativo;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\Dto\SolicitudLiquidacion;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\CalculadoraLiquidacion;
use App\Services\Creditos\Motor\Dto\Liquidacion;
use App\Services\Creditos\Motor\Dto\PagoAAplicar;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Fecha;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Liquidación anticipada (00 1.7). La cotización vale solo para su fecha; al confirmar se
 * recalcula a hoy y el monto recibido debe cubrirla.
 */
class LiquidacionService
{
    private const NUEVO = 'liquidacion';
    private const ESTADOS_LIQUIDABLES = [CreditoEstado::Activo, CreditoEstado::Castigado];

    public function __construct(
        private readonly CargadorCredito $cargador,
        private readonly PersistidorReparto $persistidor,
        private readonly AplicadorPagos $aplicador,
        private readonly CalculadoraLiquidacion $calculadora,
        private readonly CajaCredito $caja,
        private readonly CorrelativoCreditoService $correlativos,
        private readonly AlcanceCartera $alcance,
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    public function cotizar(Credito $credito, ?Fecha $fecha, User $usuario): Liquidacion
    {
        $this->validar($credito, $usuario);
        $hoy = $this->reloj->hoy();
        $fecha ??= $hoy;
        if ($fecha->esAnteriorA($hoy)) {
            throw new HttpException(422, 'La liquidación se cotiza para hoy o una fecha futura.');
        }

        return $this->calcular($credito, $fecha);
    }

    /** Liquidación a una fecha con los pagos válidos actuales (también la usa la renovación). */
    public function calcular(Credito $credito, Fecha $fecha): Liquidacion
    {
        $carga = $this->cargador->cargar($credito);

        return $this->calculadora->calcular($carga->estado, $this->aplicador->aplicar($carga->estado, $carga->pagos, $fecha), $fecha);
    }

    public function liquidar(Credito $credito, SolicitudLiquidacion $solicitud, User $usuario): CreditoPago
    {
        return DB::transaction(fn (): CreditoPago => $this->ejecutar($credito, $solicitud, $usuario));
    }

    private function ejecutar(Credito $credito, SolicitudLiquidacion $s, User $usuario): CreditoPago
    {
        $credito = Credito::whereKey($credito->id)->lockForUpdate()->firstOrFail();
        $this->validar($credito, $usuario);
        $hoy = $this->reloj->hoy();
        $sesion = $this->caja->sesionAbierta($usuario);

        $carga = $this->cargador->cargar($credito);
        $monto = $this->calculadora->calcular($carga->estado, $this->aplicador->aplicar($carga->estado, $carga->pagos, $hoy), $hoy)->montoLiquidacion;
        if ($s->montoRecibido < $monto) {
            throw new HttpException(422, 'El monto recibido no cubre la liquidación de hoy (S/ ' . Dinero::aSoles($monto) . ').');
        }

        $nuevo = new PagoAAplicar(self::NUEVO, $hoy, PHP_INT_MAX, $s->montoRecibido, OrigenPago::Liquidacion, DestinoExcedente::Devolver);
        $resultado = $this->aplicador->aplicar($carga->estado, [...$carga->pagos, $nuevo], $hoy);
        $reparto = $resultado->pago(self::NUEVO);

        $pago = CreditoPago::create([
            'credito_id' => $credito->id,
            'numero_recibo' => $this->correlativos->siguiente(TipoCorrelativo::Recibo),
            'monto_recibido' => Dinero::aSoles($s->montoRecibido),
            'monto_aplicado' => Dinero::aSoles($reparto->montoAplicado),
            'monto_excedente' => Dinero::aSoles($reparto->montoExcedente),
            'destino_excedente' => $reparto->montoExcedente > 0 ? DestinoExcedente::Devolver : null,
            'fecha_pago' => $this->reloj->ahora()->format('Y-m-d H:i:s'),
            'origen' => OrigenPago::Liquidacion,
            'es_cierre' => true,
            'pagado_por_cliente_id' => $credito->cliente_id,
            'payment_method_id' => $s->paymentMethodId,
            'referencia' => $s->referencia,
            'clave_idempotencia' => $s->clave,
            'estado' => PagoEstado::Valido,
            'registrado_por' => $usuario->id,
        ]);

        $entrada = $this->caja->entrada($sesion, $usuario, CajaCredito::PAGO, $pago->id, $s->montoRecibido, $s->paymentMethodId, $credito->cliente, "Liquidación {$pago->numero_recibo} crédito {$credito->numero_credito}");
        $pago->update(['cash_movement_id' => $entrada->id]);
        if ($reparto->montoExcedente > 0) {
            $this->caja->salida($sesion, $usuario, CajaCredito::DEVOLUCION_EXCEDENTE, $pago->id, $reparto->montoExcedente, $s->paymentMethodId, $credito->cliente, "Vuelto de liquidación {$pago->numero_recibo}");
        }

        $this->persistidor->persistir($credito, $carga, $resultado, [self::NUEVO => $pago->id]);
        $this->auditoria->registrar('credito.liquidar', $pago, $credito->id, null, ['monto_liquidacion' => Dinero::aSoles($monto)], null, $usuario);

        return $pago->refresh();
    }

    private function validar(Credito $credito, User $usuario): void
    {
        $this->alcance->asegurar($credito, $usuario);
        if (! in_array($credito->estado, self::ESTADOS_LIQUIDABLES, true)) {
            throw new HttpException(422, 'Solo se liquida un crédito activo o castigado.');
        }
    }
}
