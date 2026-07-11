<?php

namespace App\Services;

use App\Models\Sale\SaleDetail;
use InvalidArgumentException;

// ── Prorrateo de montos para una línea de Nota de Crédito/Débito PARCIAL ──
// Para notas TOTALES no se usa esta clase — se copian los montos de
// sale_details tal cual (cero riesgo de descuadre). Esta clase solo entra
// en juego cuando se acredita/debita una cantidad distinta a la original:
// escala proporcionalmente los montos ya almacenados (aritmética pura sobre
// tasas ya congeladas), sin re-resolver ninguna regla tributaria. Ver plan,
// "Modelo de datos" → "montos de línea".
class NoteDetailAmountCalculator
{
    public function prorratear(SaleDetail $original, float $cantidadNota): array
    {
        $cantidadOriginal = (float) $original->quantity;

        if ($cantidadOriginal <= 0) {
            throw new InvalidArgumentException(
                "La línea original sale_detail #{$original->id} tiene cantidad inválida ({$cantidadOriginal})."
            );
        }

        $factor = $cantidadNota / $cantidadOriginal;

        return [
            // ── Tasas/clasificación — copiadas tal cual, nunca escaladas ──
            'tip_afe_igv'    => (string) $original->tip_afe_igv,
            'porcentaje_igv' => $original->porcentaje_igv,
            'tipo_isc'       => $original->tipo_isc,
            'percentage_isc' => $original->percentage_isc,
            'monto_isc_fijo' => $original->monto_isc_fijo,
            'per_icbper'     => $original->per_icbper,
            'price_base'     => $original->price_base,   // ya es valor unitario
            'price_final'    => $original->price_final,  // ya es valor unitario
            'unidad_medida'  => $original->unidad_medida,
            'description'    => $original->description,

            // ── Montos de línea — escalados proporcionalmente por cantidad ──
            'quantity'        => $cantidadNota,
            'discount'        => round((float) $original->discount * $factor, 2),
            'subtotal'        => round((float) $original->subtotal * $factor, 2),
            'igv'             => round((float) $original->igv * $factor, 2),
            'mto_valor_venta' => round((float) $original->mto_valor_venta * $factor, 2),
            'mto_base_igv'    => round((float) $original->mto_base_igv * $factor, 2),
            'total_impuestos' => round((float) $original->total_impuestos * $factor, 2),
            'icbper'          => round((float) $original->icbper * $factor, 2),
            'isc'             => round((float) $original->isc * $factor, 2),
        ];
    }
}
