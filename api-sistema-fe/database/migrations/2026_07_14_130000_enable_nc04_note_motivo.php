<?php
// database/migrations/2026_07_14_130000_enable_nc04_note_motivo.php
//
// Habilita disponible_flujo_simple para NC04 (Descuento global) tras
// validarlo contra SUNAT BETA real: FC01-8 aceptada.
//
// En el camino se corrigieron dos bugs reales descubiertos por esta prueba:
//   - TotalesComprobanteCalculator::calcular() leía $d->subtotal /
//     $d->porcentaje_igv con acceso a propiedad de objeto dentro del
//     cálculo de IGV con descuento global — funciona para ventas
//     (sale_details son Eloquent models) pero no para notas (clonarLineasTotal()
//     devuelve arrays planos), dejando mto_igv en 0 en silencio. Fix: data_get().
//   - El tope de "cantidad disponible para acreditar" (armarLineasParciales(),
//     validarYReservarCorrelativoNota()) sumaba quantity de TODAS las notas
//     aceptadas sobre una línea, incluyendo motivos que ajustan valor sin
//     devolución física (04/05/08/09 — mantienen quantity = cantidad
//     original). Eso bloqueaba una NC04 después de una NC05 sobre la misma
//     línea con "cantidad disponible: 0". Fix:
//     NotaElectronicaController::MOTIVOS_VALOR_SIN_CANTIDAD excluye esos
//     motivos de la suma.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('note_motivos')
            ->where('catalogo', '09')->where('codigo', '04')
            ->update(['disponible_flujo_simple' => true]);
    }

    public function down(): void
    {
        DB::table('note_motivos')
            ->where('catalogo', '09')->where('codigo', '04')
            ->update(['disponible_flujo_simple' => false]);
    }
};
