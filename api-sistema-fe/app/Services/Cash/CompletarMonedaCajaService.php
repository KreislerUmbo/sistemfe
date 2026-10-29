<?php

namespace App\Services\Cash;

use App\Models\Cash\CashMovement;
use Illuminate\Support\Facades\DB;

/**
 * T-009 — los movimientos de caja anteriores a la columna `currency` quedaron en 'PEN'
 * (default). Calcula la moneda real según el documento de origen y pasa a 'USD' los que
 * correspondan. Es un completado de dato faltante: monto, método y dirección no se tocan.
 * Cada cambio queda en ajustes_moneda_caja. Correrlo de nuevo no cambia nada.
 */
class CompletarMonedaCajaService
{
    /**
     * @return array{cambios: list<array{movimiento_id: int, moneda_anterior: string, moneda_nueva: string}>, revisar: list<array{movimiento_id: int, motivo: string}>}
     */
    public function completar(bool $simular): array
    {
        $cambios = [];
        $revisar = [];
        // Moneda que debe tener cada movimiento ya recorrido (las correcciones heredan la del original).
        $calculada = [];

        foreach (DB::table('cash_movements')->orderBy('id')->get() as $mov) {
            [$moneda, $motivo] = $this->monedaEsperada($mov, $calculada);

            if ($moneda === null) {
                $revisar[] = ['movimiento_id' => (int) $mov->id, 'motivo' => $motivo];

                continue;
            }

            $calculada[$mov->id] = $moneda;

            if ($moneda === $mov->currency) {
                continue;
            }

            $cambios[] = ['movimiento_id' => (int) $mov->id, 'moneda_anterior' => $mov->currency, 'moneda_nueva' => $moneda];

            if (! $simular) {
                DB::table('cash_movements')->where('id', $mov->id)->update(['currency' => $moneda]);
                DB::table('ajustes_moneda_caja')->insert([
                    'cash_movement_id' => $mov->id,
                    'moneda_anterior' => $mov->currency,
                    'moneda_nueva' => $moneda,
                ]);
            }
        }

        return ['cambios' => $cambios, 'revisar' => $revisar];
    }

    /** @return array{0: ?string, 1: string} [moneda o null, motivo si es null] */
    private function monedaEsperada(object $mov, array $calculada): array
    {
        switch ($mov->reference_type) {
            case 'sale':
                return $this->deDocumento(DB::table('sales')->where('id', $mov->reference_id)->value('currency'), 'venta');
            case 'advance':
                return $this->deDocumento(DB::table('advances')->where('id', $mov->reference_id)->value('currency'), 'adelanto');
            case 'payment_receipt':
                $monedas = DB::table('payment_applications as pa')
                    ->join('sales as s', 's.id', '=', 'pa.sale_id')
                    ->where('pa.payment_receipt_id', $mov->reference_id)
                    ->pluck('s.currency')
                    ->map(fn ($m) => CashMovement::normalizarMoneda($m) ?? '?')
                    ->unique()
                    ->values();
                if ($monedas->isEmpty()) {
                    return ['PEN', '']; // recibo sin aplicaciones (todo saldo a favor): soles
                }
                if ($monedas->count() > 1) {
                    return [null, 'recibo con ventas en monedas distintas'];
                }

                return $monedas->first() === '?' ? [null, 'moneda de venta no reconocida'] : [$monedas->first(), ''];
            case 'cash_movement':
                $original = $mov->corrected_movement_id ?? $mov->reference_id;

                return isset($calculada[$original])
                    ? [$calculada[$original], '']
                    : [null, 'corrección de un movimiento que no se pudo resolver'];
            default:
                // credito_*, manuales y apertura: siempre soles.
                return ['PEN', ''];
        }
    }

    /** @return array{0: ?string, 1: string} */
    private function deDocumento(?string $moneda, string $documento): array
    {
        if ($moneda === null) {
            return [null, "{$documento} inexistente"];
        }
        $normalizada = CashMovement::normalizarMoneda($moneda);

        return $normalizada === null ? [null, "moneda de {$documento} no reconocida (\"{$moneda}\")"] : [$normalizada, ''];
    }
}
