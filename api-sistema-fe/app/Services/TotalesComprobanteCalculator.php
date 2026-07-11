<?php

namespace App\Services;

use Illuminate\Support\Collection;

// ── Cálculo de totales SUNAT para un comprobante ──────────────────────────
// Extraído de FacturacionElectronicaController::calcularTotalesComprobante()
// para poder reutilizar exactamente la misma lógica de agrupación por
// tip_afe_igv y el mismo redondeo a 1 decimal que exige SUNAT, tanto para
// ventas (sale_details) como para notas de crédito/débito (note_details) —
// ver plan, "Cálculo de totales — extraer sin duplicar". No se cambió NADA
// del comportamiento original, solo se parametrizó sobre una colección de
// detalles + el descuento global, en vez de leer $venta->sale_details
// directamente.
class TotalesComprobanteCalculator
{
    // ── Calcular totales del comprobante ──────────────────────────────
    // Agrupa los montos por tipo de afectación IGV para armar el Invoice/Note
    // IMPORTANTE: tip_afe_igv siempre se compara como string
    //
    // $detalles: colección de SaleDetail o NoteDetail (cualquier objeto con
    // los atributos tip_afe_igv, subtotal, igv, icbper, isc)
    public function calcular(Collection $detalles, float $descuentoGlobal = 0): array
    {
        $datos = [];

        // ── Operaciones gravadas (tip_afe_igv = '10') ─────────────────
        // Son las ventas normales con IGV 18%
        $datos['mto_oper_gravadas'] = $detalles
            ->where('tip_afe_igv', '10')
            ->sum('subtotal');

        // ── Operaciones exoneradas (tip_afe_igv = '20') ───────────────
        // Ventas sin IGV por Ley 27037 (Amazonía) o Apéndice I/II
        $datos['mto_oper_exoneradas'] = $detalles
            ->where('tip_afe_igv', '20')
            ->sum('subtotal');

        // ── Operaciones inafectas (tip_afe_igv = '30') ────────────────
        // Fuera del ámbito del IGV — diferente a exonerado
        $datos['mto_oper_inafectas'] = $detalles
            ->where('tip_afe_igv', '30')
            ->sum('subtotal');

        // ── Descuento global ──────────────────────────────────────────
        // El descuento global reduce la base imponible proporcionalmente
        // según cuánto corresponde a cada tipo de operación
        if ($descuentoGlobal > 0) {
            // Total de operaciones que pueden recibir el descuento
            $total_operaciones = $datos['mto_oper_gravadas']
                + $datos['mto_oper_exoneradas']
                + $datos['mto_oper_inafectas'];

            // Distribuir proporcionalmente para no descontar doble
            if ($total_operaciones > 0) {
                if ($datos['mto_oper_gravadas'] > 0) {
                    $proporcion_gravadas = $datos['mto_oper_gravadas'] / $total_operaciones;
                    $datos['mto_oper_gravadas'] -= round($descuentoGlobal * $proporcion_gravadas, 2);
                }
                if ($datos['mto_oper_exoneradas'] > 0) {
                    $proporcion_exoneradas = $datos['mto_oper_exoneradas'] / $total_operaciones;
                    $datos['mto_oper_exoneradas'] -= round($descuentoGlobal * $proporcion_exoneradas, 2);
                }
                if ($datos['mto_oper_inafectas'] > 0) {
                    $proporcion_inafectas = $datos['mto_oper_inafectas'] / $total_operaciones;
                    $datos['mto_oper_inafectas'] -= round($descuentoGlobal * $proporcion_inafectas, 2);
                }
            }
        }

        // ── Exportaciones (tip_afe_igv = '40') ────────────────────────
        $datos['mto_oper_exportacion'] = $detalles
            ->where('tip_afe_igv', '40')
            ->sum('subtotal');

        // ── Operaciones gratuitas (retiros — tip_afe_igv 11 al 37) ────
        // Son retiros por premio u otras operaciones que no generan cobro
        $codigos_operaciones_gratuitas = ['11','12','13','14','15','16','31','32','33','34','35','36','37'];
        $datos['mto_oper_gratuitas'] = $detalles
            ->whereIn('tip_afe_igv', $codigos_operaciones_gratuitas)
            ->sum('subtotal');

        // ── IVAP — Arroz Pilado (tip_afe_igv = '17') ─────────────────
        $datos['mto_base_ivap'] = $detalles
            ->where('tip_afe_igv', '17')
            ->sum('subtotal');

        $datos['mto_ivap'] = $detalles
            ->where('tip_afe_igv', '17')
            ->sum('igv');

        // ── IGV total del comprobante ─────────────────────────────────
        // Solo se suma el IGV de operaciones que generan IGV real
        // Las exoneradas e inafectas siempre tienen igv = 0
        $codigos_con_igv = ['10', '11'];
        $datos['mto_igv'] = round(
            $detalles->whereIn('tip_afe_igv', $codigos_con_igv)->sum('igv'),
            2
        );

        // IGV de operaciones gratuitas (se declara separado en el XML)
        $datos['mto_igv_gratuitas'] = $detalles
            ->whereIn('tip_afe_igv', $codigos_operaciones_gratuitas)
            ->sum('igv');

        // ── Otros impuestos ───────────────────────────────────────────
        $datos['icbper'] = $detalles->sum('icbper');
        $datos['isc']    = $detalles->sum('isc');

        // Total de impuestos = IGV + ICBPER + IVAP + ISC
        $datos['total_impuestos'] = $datos['mto_igv']
            + $datos['icbper']
            + $datos['mto_ivap']
            + $datos['isc'];

        // ── Valor de venta = base total sin impuestos ─────────────────
        // Suma los subtotales de todas las operaciones menos el descuento global
        $codigos_todas_operaciones = ['10','20','30','40','17'];
        $datos['valor_venta'] = $detalles
            ->whereIn('tip_afe_igv', $codigos_todas_operaciones)
            ->sum('subtotal') - $descuentoGlobal;

        // ── Subtotal = valor venta + total impuestos ──────────────────
        $datos['sub_total'] = $datos['valor_venta'] + $datos['total_impuestos'];

        // ── Monto a pagar con redondeo bancario ───────────────────────
        // SUNAT exige redondeo a 1 decimal en el mto_imp_venta
        // Usar round() con redondeo bancario (no floor que siempre redondea abajo)
        $datos['mto_imp_venta'] = round($datos['sub_total'], 1);

        // El redondeo es la diferencia entre el total redondeado y el calculado
        $datos['redondeo'] = round($datos['mto_imp_venta'] - $datos['sub_total'], 2);

        return $datos;
    }
}
