<?php
// database/migrations/2026_07_14_140000_enable_nc03_nc10_note_motivos.php
//
// Habilita disponible_flujo_simple para NC03 (Corrección por error en la
// descripción) y NC10 (Otros conceptos) — forzados a total-only en la
// migración de Fase 1 (permite_parcial=false), sin código nuevo: como
// notas totales, clonarLineasTotal() las clona sin tocar montos, solo con
// una descripción distinta. Validadas contra SUNAT BETA real:
//   - NC03 → FC01-9 aceptada
//   - NC10 → FC01-10 aceptada

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('note_motivos')
            ->where('catalogo', '09')->whereIn('codigo', ['03', '10'])
            ->update(['disponible_flujo_simple' => true]);
    }

    public function down(): void
    {
        DB::table('note_motivos')
            ->where('catalogo', '09')->whereIn('codigo', ['03', '10'])
            ->update(['disponible_flujo_simple' => false]);
    }
};
