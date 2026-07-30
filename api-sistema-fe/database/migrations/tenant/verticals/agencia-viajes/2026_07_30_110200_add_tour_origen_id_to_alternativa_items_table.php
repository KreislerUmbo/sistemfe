<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_30_110200_add_tour_origen_id_to_alternativa_items_table.php
//
// Sesión 11b4 — cuando un alternativa_item se generó explotando un
// paquete_combo (cada línea de un tour incluido, ver ComboExplosionService),
// esta columna guarda de qué paquetes_plantilla (tour_simple) vino. Uso:
// agrupación visual en PDF/cotización ("Día 1: Alto Mayo Full Day —
// incluye: ..."), NO afecta precio ni bloquea edición — el vendedor sigue
// pudiendo agregar/quitar/modificar ítems sueltos libremente después de
// cargar. Nullable: un ítem cargado manualmente o desde un tour_simple
// suelto (no un combo) no tiene origen de combo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->foreignId('tour_origen_id')->nullable()->after('opcion_mayorista_id')->constrained('paquetes_plantilla');
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tour_origen_id');
        });
    }
};
