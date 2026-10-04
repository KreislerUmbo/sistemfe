<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\MotivoCierre;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Models\Creditos\CreditoPagoAplicacion;
use App\Services\Creditos\Dto\CreditoCargado;
use App\Services\Creditos\Motor\Dto\ResultadoAplicacion;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Escribe un ResultadoAplicacion (03-api "persistidor", 00 1.8): aplicaciones previas pasan
 * a historial (vigente=false), las nuevas entran con generacion+1, los acumulados de las
 * cuotas se reescriben desde el reparto y el crédito se cierra o se reabre.
 */
class PersistidorReparto
{
    /** @param array<string, int> $idsNuevos referencia temporal del motor => credito_pagos.id */
    public function persistir(Credito $credito, CreditoCargado $carga, ResultadoAplicacion $resultado, array $idsNuevos = []): void
    {
        $this->validar($resultado);

        $generacion = (int) CreditoPagoAplicacion::where('credito_id', $credito->id)->max('generacion') + 1;
        CreditoPagoAplicacion::where('credito_id', $credito->id)->where('vigente', true)->update(['vigente' => false]);

        $ahora = now();
        $filas = [];
        foreach ($resultado->aplicaciones as $aplicacion) {
            $filas[] = [
                'credito_id' => $credito->id,
                'pago_id' => is_int($aplicacion->referenciaPago) ? $aplicacion->referenciaPago : $idsNuevos[$aplicacion->referenciaPago],
                'cuota_id' => $carga->cuotasPorNumero[$aplicacion->numeroCuota]->id,
                'concepto' => $aplicacion->concepto->value,
                'monto' => Dinero::aSoles($aplicacion->monto),
                'generacion' => $generacion,
                'vigente' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }
        foreach (array_chunk($filas, 500) as $lote) {
            CreditoPagoAplicacion::insert($lote);
        }

        foreach ($resultado->cuotas as $saldada) {
            $carga->cuotasPorNumero[$saldada->numero]->update([
                'capital_pagado' => Dinero::aSoles($saldada->capitalPagado),
                'interes_pagado' => Dinero::aSoles($saldada->interesPagado),
                'cargo_pagado' => Dinero::aSoles($saldada->cargoPagado),
                'mora_pagada' => Dinero::aSoles($saldada->moraPagada),
                'interes_condonado' => Dinero::aSoles($saldada->interesCondonado),
                'mora_condonada' => Dinero::aSoles($resultado->mora($saldada->numero)->moraCondonada),
                'estado' => $saldada->estado,
                'fecha_pago' => $saldada->fechaPago?->aTexto(),
                'dias_atraso_al_pagar' => $saldada->diasAtrasoAlPagar,
            ]);
        }

        $this->actualizarEstado($credito, $resultado, $idsNuevos);
    }

    private function validar(ResultadoAplicacion $resultado): void
    {
        if ($resultado->cierresInsuficientes !== []) {
            $cierres = CreditoPago::whereIn('id', $resultado->cierresInsuficientes)->get(['numero_recibo', 'origen']);
            $recibos = $cierres->pluck('numero_recibo')->implode(', ');
            // Una renovación no se anula como pago: se deshace anulando el crédito nuevo (00 1.12).
            $comoDeshacer = $cierres->contains(fn (CreditoPago $p): bool => $p->origen === OrigenPago::Renovacion)
                ? 'Anula primero el crédito de la renovación.'
                : 'Anula primero esa operación.';
            throw new HttpException(422, "No se puede recalcular: la operación de cierre {$recibos} dejaría de cubrir la deuda. {$comoDeshacer}");
        }

        // Un pago ya registrado conserva lo que el crédito recibió; si el recálculo dejara parte
        // sin aplicar (ej. un retroactivo que termina de pagar antes), no hay a dónde devolverlo.
        foreach ($resultado->pagos as $pago) {
            if (is_int($pago->referenciaPago) && $pago->montoExcedente > 0) {
                throw new HttpException(422, 'El recálculo dejaría dinero de un pago anterior sin aplicar. Revisa los pagos del crédito antes de continuar.');
            }
        }
    }

    /** @param array<string, int> $idsNuevos */
    private function actualizarEstado(Credito $credito, ResultadoAplicacion $resultado, array $idsNuevos): void
    {
        if ($resultado->finalizado && in_array($credito->estado, [CreditoEstado::Activo, CreditoEstado::Castigado], true)) {
            $credito->update([
                'motivo_cierre' => $this->motivoCierre($credito, $resultado, $idsNuevos),
                'estado' => CreditoEstado::Finalizado,
            ]);

            return;
        }

        if (! $resultado->finalizado && $credito->estado === CreditoEstado::Finalizado) {
            // Se anuló el pago que lo cerraba (1.8): vuelve a su estado previo.
            $credito->update([
                'estado' => $credito->fecha_castigo !== null ? CreditoEstado::Castigado : CreditoEstado::Activo,
                'motivo_cierre' => null,
            ]);
        }
    }

    /** @param array<string, int> $idsNuevos */
    private function motivoCierre(Credito $credito, ResultadoAplicacion $resultado, array $idsNuevos): MotivoCierre
    {
        foreach (array_reverse($resultado->pagos) as $pago) {
            if (! $pago->fueLiquidacion) {
                continue;
            }
            $id = is_int($pago->referenciaPago) ? $pago->referenciaPago : ($idsNuevos[$pago->referenciaPago] ?? null);
            $origen = CreditoPago::whereKey($id)->value('origen');

            return match ($origen) {
                OrigenPago::Renovacion => MotivoCierre::Renovacion,
                OrigenPago::VentaPrenda => MotivoCierre::VentaPrenda,
                default => MotivoCierre::LiquidacionAnticipada,
            };
        }

        return $credito->estado === CreditoEstado::Castigado ? MotivoCierre::RecuperoCastigo : MotivoCierre::PagadoCompleto;
    }
}
