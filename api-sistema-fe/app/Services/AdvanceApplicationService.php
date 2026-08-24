<?php

namespace App\Services;

use App\Models\Advance\Advance;
use App\Models\Advance\AdvanceApplication;
use App\Models\Sale\Sale;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Extraído de SaleController::store() (Módulo Adelantos — hallazgos de
// auditoría, 2026-08-21) al aparecer un segundo consumidor
// (ReservaFacturacionController::store(), Tier 0 de la conexión
// Adelantos↔Reservas) — mismo criterio de extracción ya usado en el
// proyecto para CashCorrectionService/ExpectedCashCalculator: "un solo
// punto de verdad, no dos que empiecen iguales".
//
// Responsabilidad única: validar y persistir la aplicación de uno o más
// Advance contra un Sale ya creado (lockForUpdate + cliente/moneda/
// n_operacion/saldo + AdvanceApplication + applied_amount/refreshStatus).
// NO toca total/debt/state_payment del Sale — cada caller decide cómo
// reflejar el monto aplicado en su propia cabecera, porque difiere: en
// SaleController::store() el frontend ya manda debt/state_payment
// pre-netos; en ReservaFacturacionController::store() esos campos se
// calculan enteramente en el backend.
class AdvanceApplicationService
{
    /**
     * @param array<array{advance_id:int, amount:float}> $aplicaciones
     * @return float Suma total aplicada (para que el caller reduzca total/debt).
     */
    public function aplicar(Sale $venta, array $aplicaciones): float
    {
        $totalAplicado = 0.0;

        foreach ($aplicaciones as $aplicacion) {
            $adelanto = Advance::where('id', $aplicacion['advance_id'])
                ->lockForUpdate()
                ->first();

            if (!$adelanto) {
                throw new HttpException(422, "El adelanto #{$aplicacion['advance_id']} no existe.");
            }

            if ((int) $adelanto->client_id !== (int) $venta->client_id) {
                throw new HttpException(422, "El adelanto #{$adelanto->id} no pertenece a este cliente.");
            }

            if ($adelanto->currency !== $venta->currency) {
                throw new HttpException(
                    422,
                    "El adelanto #{$adelanto->id} está en {$adelanto->currency} y la venta en " .
                    "{$venta->currency} — no se puede aplicar entre monedas distintas."
                );
            }

            if (!$adelanto->sale || !$adelanto->sale->n_operacion) {
                throw new HttpException(
                    422,
                    "El adelanto #{$adelanto->id} aún no fue enviado a SUNAT — no se puede aplicar todavía."
                );
            }

            $montoSolicitado = round((float) $aplicacion['amount'], 2);
            $saldoDisponible = $adelanto->availableBalance();

            if ($montoSolicitado > $saldoDisponible) {
                throw new HttpException(
                    422,
                    "El adelanto #{$adelanto->id} no tiene saldo suficiente " .
                    "(disponible: S/ " . number_format($saldoDisponible, 2) . ")."
                );
            }

            AdvanceApplication::create([
                "advance_id"     => $adelanto->id,
                "sale_id"        => $venta->id,
                "amount_applied" => $montoSolicitado,
            ]);

            $adelanto->applied_amount = round((float) $adelanto->applied_amount + $montoSolicitado, 2);
            $adelanto->refreshStatus();
            $adelanto->save();

            $totalAplicado += $montoSolicitado;
        }

        return round($totalAplicado, 2);
    }
}
