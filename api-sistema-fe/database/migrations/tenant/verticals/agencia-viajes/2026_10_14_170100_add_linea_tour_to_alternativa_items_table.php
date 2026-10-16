<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_14_170100_add_linea_tour_to_alternativa_items_table.php
//
// Fila 13, fase 1b (plan-tours-venta-simple-costo-real.md N6, N7, N14, §3.9b,
// §3.12). La línea de tour (origen_tipo='tour'):
// - carga_tour: identifica las líneas de UNA carga de un tour (una por grupo de
//   IGV cuando el Incluye mezcla IGV). Se mueven y quitan juntas; la 1c
//   permite mezclar IGV en un comprobante solo dentro de una misma carga (D1).
// - carga_combo / combo_origen_id: las líneas de los tours de un combo,
//   agrupadas como "Combo X".
// - tour_linea: datos con los que se calculó (precios y costos unitarios, grupos
//   de IGV con su costo, descuento del combo, fecha del tour); permite
//   recalcular al cambiar pasajeros sin volver a leer el catálogo.
// - temporada_id: temporada aplicada (catálogo central, sin FK real).
// - desglose_costo_snapshot: costo por servicio del Incluye al cotizar.
// - precio_manual: el vendedor cambió el precio; los recálculos no lo pisan.
// Solo aditiva (§5b).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->uuid('carga_tour')->nullable()->index();
            $table->uuid('carga_combo')->nullable()->index();
            $table->foreignId('combo_origen_id')->nullable()->constrained('paquetes_plantilla')->nullOnDelete();
            $table->json('tour_linea')->nullable();
            $table->unsignedBigInteger('temporada_id')->nullable(); // temporadas.id (central)
            $table->json('desglose_costo_snapshot')->nullable();
            $table->boolean('precio_manual')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('combo_origen_id');
            $table->dropIndex(['carga_tour']);
            $table->dropIndex(['carga_combo']);
            $table->dropColumn(['carga_tour', 'carga_combo', 'tour_linea', 'temporada_id', 'desglose_costo_snapshot', 'precio_manual']);
        });
    }
};
