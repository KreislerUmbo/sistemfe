<?php
// database/migrations/2026_07_14_110000_enable_modo_monto_note_motivos.php
//
// Habilita disponible_flujo_simple para los motivos de "modo monto"
// (NoteDetailAmountCalculator::porMonto(), NotaElectronicaController::
// armarLineasParciales()) después de validarlos contra SUNAT BETA real:
//   - NC05 (Descuento por ítem)      → FC01-6 aceptada
//   - ND02 (Aumento en el valor)     → FD01-1 aceptada
// NC08 (Bonificación) y NC09 (Disminución en el valor) comparten el mismo
// código sin ninguna rama específica por motivo — se habilitan junto con
// NC05 con la misma evidencia, ya que el mecanismo probado es genérico
// (no hay lógica condicional por cod_motivo dentro de porMonto()).
//
// Nota de diseño confirmada en esta ronda: el primer intento (mantener
// price_base original, solo reducir mto_valor_venta) fue rechazado por
// SUNAT (error 3271) — exige LineExtensionAmount == precio_unitario ×
// cantidad sin excepción. El fix recalcula price_base/price_final a partir
// del monto, no de la venta original (ver NoteDetailAmountCalculator::porMonto()).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('note_motivos')
            ->where('catalogo', '09')->whereIn('codigo', ['05', '08', '09'])
            ->update(['disponible_flujo_simple' => true]);

        DB::table('note_motivos')
            ->where('catalogo', '10')->where('codigo', '02')
            ->update(['disponible_flujo_simple' => true]);
    }

    public function down(): void
    {
        DB::table('note_motivos')
            ->where('catalogo', '09')->whereIn('codigo', ['05', '08', '09'])
            ->update(['disponible_flujo_simple' => false]);

        DB::table('note_motivos')
            ->where('catalogo', '10')->where('codigo', '02')
            ->update(['disponible_flujo_simple' => false]);
    }
};
