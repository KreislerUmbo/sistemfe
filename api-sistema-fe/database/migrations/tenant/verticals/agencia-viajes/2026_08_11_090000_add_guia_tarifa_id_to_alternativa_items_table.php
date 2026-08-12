<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_11_090000_add_guia_tarifa_id_to_alternativa_items_table.php
//
// Cierra el hueco documentado en desdePlantilla()/crearItemGuia(): hasta
// ahora un guía nunca se convertía en un ítem real con precio dentro de
// una cotización — ni cargado manual, ni al explotar un combo que lo
// incluye (se avisaba en "guías pendientes", pero eso es solo informativo,
// nunca sumaba al total). Esto es exclusivamente sobre el COSTO — la
// asignación de QUÉ guía puntual sigue siendo a nivel reserva
// (reserva_items.guia_id), sin cambios.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->foreignId('guia_tarifa_id')->nullable()
                ->after('paquete_plantilla_id')
                ->constrained('guia_tarifas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guia_tarifa_id');
        });
    }
};
