<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_04_121532_add_margen_minimo_aceptable_to_configuracion_agencia_table.php
//
// Margen mínimo aceptable (%) para el tab "Incluye" de tour_simple, ahora
// configurable por agencia — antes vivía hardcodeado en detalle.vue
// (MARGEN_MINIMO_ACEPTABLE_PCT). Mismo patrón de retrofit ya usado en esta
// tabla singleton (ver 2026_08_03_090000_...): ALTER con default, sin
// re-insertar la fila (la fila existente hereda el default).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->decimal('margen_minimo_aceptable_pct', 5, 2)->default(20.00)
                ->after('dias_aviso_vencimiento_cotizacion');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->dropColumn('margen_minimo_aceptable_pct');
        });
    }
};
