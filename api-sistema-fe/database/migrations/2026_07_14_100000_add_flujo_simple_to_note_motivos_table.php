<?php
// database/migrations/2026_07_14_100000_add_flujo_simple_to_note_motivos_table.php
//
// De los 13 motivos del catálogo, solo 6 quedaron verificados contra SUNAT BETA
// en la sesión anterior (NC 01/02/06/07, ND 01/03). `disponible_flujo_simple`
// oculta el resto del formulario hasta que se prueben y se habiliten uno por
// uno — ver plan de la sesión "Notas de Crédito/Débito — parte 2".
//
// `modo_parcial` le dice al frontend qué input mostrar por línea en modo
// parcial ('cantidad' = input de cantidad a acreditar/debitar, como NC07;
// 'monto' = input de importe, para motivos que ajustan valor sin devolver
// unidades — NC05/08/09, ND02). NULL para motivos sin modo parcial (total-only
// o concepto libre).
//
// NC03 y NC10 pasan a permite_parcial=false: una corrección de descripción o
// un concepto "otros" genérico no debe mover montos — como total-only,
// clonarLineasTotal() los clona tal cual y el usuario solo aporta la
// descripción, sin ambigüedad de cálculo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('note_motivos', function (Blueprint $table) {
            $table->boolean('disponible_flujo_simple')->default(false);
            $table->string('modo_parcial', 10)->nullable(); // 'cantidad' | 'monto'
        });

        // ── Motivos ya verificados contra SUNAT BETA ───────────────────
        DB::table('note_motivos')
            ->where('catalogo', '09')->whereIn('codigo', ['01', '02', '06', '07'])
            ->update(['disponible_flujo_simple' => true]);

        DB::table('note_motivos')
            ->where('catalogo', '10')->whereIn('codigo', ['01', '03'])
            ->update(['disponible_flujo_simple' => true]);

        // ── Modo parcial por motivo ─────────────────────────────────────
        DB::table('note_motivos')
            ->where('catalogo', '09')->where('codigo', '07')
            ->update(['modo_parcial' => 'cantidad']);

        DB::table('note_motivos')
            ->where('catalogo', '09')->whereIn('codigo', ['05', '08', '09'])
            ->update(['modo_parcial' => 'monto']);

        DB::table('note_motivos')
            ->where('catalogo', '10')->where('codigo', '02')
            ->update(['modo_parcial' => 'monto']);

        // ── NC03 / NC10: forzar total-only ──────────────────────────────
        DB::table('note_motivos')
            ->where('catalogo', '09')->whereIn('codigo', ['03', '10'])
            ->update(['permite_parcial' => false]);
    }

    public function down(): void
    {
        Schema::table('note_motivos', function (Blueprint $table) {
            $table->dropColumn(['disponible_flujo_simple', 'modo_parcial']);
        });

        DB::table('note_motivos')
            ->where('catalogo', '09')->whereIn('codigo', ['03', '10'])
            ->update(['permite_parcial' => true]);
    }
};
