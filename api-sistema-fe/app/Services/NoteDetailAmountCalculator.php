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

    // ── Ajuste de valor por MONTO (no por cantidad) ────────────────────
    // Para motivos que corrigen precio/valor de una línea ya vendida sin
    // que haya devolución física de unidades (descuento por ítem,
    // bonificación, disminución/aumento de valor — catálogo 09 códigos
    // 05/08/09, catálogo 10 código 02). La cantidad NO se escala — se
    // mantiene la original — pero a diferencia de un intento previo, el
    // precio unitario SÍ se recalcula: probado contra SUNAT BETA que
    // exige, sin excepción, LineExtensionAmount == precio_unitario ×
    // cantidad — ni siquiera con un AllowanceCharge documentando la
    // diferencia (error 3271/3272, ver comentario en
    // GreenterService::getDetallesComprobante()). Por eso acá el "precio
    // unitario" que se declara para esta línea de nota es el monto por
    // unidad del ajuste, no el precio original de venta — mismo patrón que
    // ya usa el concepto libre de ND (price_base = subtotal / quantity).
    // $montoConIgv es el importe (con IGV) que el usuario entiende como el
    // ajuste total de esa línea.
    public function porMonto(SaleDetail $original, float $montoConIgv): array
    {
        if ($montoConIgv <= 0) {
            throw new InvalidArgumentException(
                "El monto para la línea original sale_detail #{$original->id} debe ser mayor a cero."
            );
        }

        $cantidad = (float) $original->quantity;
        if ($cantidad <= 0) {
            throw new InvalidArgumentException(
                "La línea original sale_detail #{$original->id} tiene cantidad inválida ({$cantidad})."
            );
        }

        $porcentajeIgv = (float) $original->porcentaje_igv;
        $base = round($montoConIgv / (1 + $porcentajeIgv / 100), 2);
        $igv  = round($montoConIgv - $base, 2);

        return [
            // ── Tasas/clasificación — copiadas tal cual, nunca escaladas ──
            'tip_afe_igv'    => (string) $original->tip_afe_igv,
            'porcentaje_igv' => $original->porcentaje_igv,
            'tipo_isc'       => $original->tipo_isc,
            'percentage_isc' => $original->percentage_isc,
            'monto_isc_fijo' => $original->monto_isc_fijo,
            'per_icbper'     => $original->per_icbper,
            'unidad_medida'  => $original->unidad_medida,
            'description'    => $original->description,

            // ── Montos de línea — el monto ingresado ES el valor de la
            // nota; la cantidad queda informativa (no hay devolución
            // física), pero price_base/price_final se recalculan para que
            // price_base × quantity == mto_valor_venta (exigido por SUNAT) ──
            'quantity'        => $cantidad,
            'price_base'      => round($base / $cantidad, 6),
            'price_final'     => round(($base + $igv) / $cantidad, 6),
            'discount'        => 0,
            'subtotal'        => $base,
            'igv'             => $igv,
            'mto_valor_venta' => $base,
            'mto_base_igv'    => $base,
            'total_impuestos' => $igv,
            // Sin devolución de unidades, un impuesto específico por unidad
            // (ICBPER/ISC) no debería reducirse solo por un ajuste de precio
            // — hipótesis a validar contra SUNAT BETA (ver plan, Fase 2).
            'icbper'          => 0,
            'isc'             => 0,
        ];
    }
}
