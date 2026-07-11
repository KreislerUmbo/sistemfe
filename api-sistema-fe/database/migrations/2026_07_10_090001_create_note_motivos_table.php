<?php
// database/migrations/2026_07_10_090001_create_note_motivos_table.php
//
// Catálogo de motivos de Nota de Crédito (09) y Nota de Débito (10) —
// Anexo N.° 8, RS 097-2012/SUNAT, modificado por RS 193-2020/SUNAT.
// Se modela como datos (no if/else en el controlador) porque las reglas
// permite_total/permite_parcial/solo_factura varían por código y algunas
// vienen de SUNAT (no editables sin riesgo de rechazo) y otras son
// decisión de negocio propia — ver plan para el detalle de cada una.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_motivos', function (Blueprint $table) {
            $table->id();

            $table->string('catalogo', 2); // '09' NC, '10' ND
            $table->string('codigo', 2);
            $table->string('label');
            $table->boolean('permite_total')->default(true);
            $table->boolean('permite_parcial')->default(true);
            $table->boolean('solo_factura')->default(false);

            $table->timestamps();

            $table->unique(['catalogo', 'codigo']);
        });

        $ahora = now();

        // ── Catálogo 09 — Nota de Crédito ──────────────────────────────
        // Excluidos a propósito (fuera de alcance de negocio): 11 (ajustes
        // de exportación), 12 (ajustes IVAP), 13 (corrección de monto neto
        // pendiente/fechas de cuotas — edición de condiciones de crédito,
        // no ajuste de monto de venta).
        $catalogo09 = [
            ['codigo' => '01', 'label' => 'Anulación de la operación',              'permite_total' => true,  'permite_parcial' => false, 'solo_factura' => false],
            ['codigo' => '02', 'label' => 'Anulación por error en el RUC',           'permite_total' => true,  'permite_parcial' => false, 'solo_factura' => false],
            ['codigo' => '03', 'label' => 'Corrección por error en la descripción',  'permite_total' => true,  'permite_parcial' => true,  'solo_factura' => false],
            ['codigo' => '04', 'label' => 'Descuento global',                        'permite_total' => false, 'permite_parcial' => true,  'solo_factura' => true],
            ['codigo' => '05', 'label' => 'Descuento por ítem',                      'permite_total' => false, 'permite_parcial' => true,  'solo_factura' => false],
            ['codigo' => '06', 'label' => 'Devolución total',                        'permite_total' => true,  'permite_parcial' => false, 'solo_factura' => false],
            ['codigo' => '07', 'label' => 'Devolución por ítem',                     'permite_total' => false, 'permite_parcial' => true,  'solo_factura' => false],
            ['codigo' => '08', 'label' => 'Bonificación',                            'permite_total' => true,  'permite_parcial' => true,  'solo_factura' => false],
            ['codigo' => '09', 'label' => 'Disminución en el valor',                 'permite_total' => true,  'permite_parcial' => true,  'solo_factura' => false],
            ['codigo' => '10', 'label' => 'Otros conceptos',                         'permite_total' => true,  'permite_parcial' => true,  'solo_factura' => false],
        ];

        foreach ($catalogo09 as $motivo) {
            DB::table('note_motivos')->insert(array_merge($motivo, [
                'catalogo'   => '09',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]));
        }

        // ── Catálogo 10 — Nota de Débito ────────────────────────────────
        // Sin restricciones total/parcial/solo_factura identificadas.
        $catalogo10 = [
            ['codigo' => '01', 'label' => 'Intereses por mora'],
            ['codigo' => '02', 'label' => 'Aumento en el valor'],
            ['codigo' => '03', 'label' => 'Penalidades / Otros conceptos'],
        ];

        foreach ($catalogo10 as $motivo) {
            DB::table('note_motivos')->insert(array_merge($motivo, [
                'catalogo'        => '10',
                'permite_total'   => true,
                'permite_parcial' => true,
                'solo_factura'    => false,
                'created_at'      => $ahora,
                'updated_at'      => $ahora,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('note_motivos');
    }
};
