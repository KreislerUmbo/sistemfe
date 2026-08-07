<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_06_090100_add_cama_adicional_nino_defaults_to_configuracion_agencia_table.php
//
// Sesión 11o — defaults de agencia para los 2 campos nuevos de
// opciones_hotel (ver 2026_08_06_090000_...): mismo patrón que
// edad_max_infante/edad_max_nino ya existentes en esta tabla (esos son el
// umbral GENERAL de la agencia para clasificar tipo_pax de un pasajero,
// éstos son el default específico que se precarga al crear un OpcionHotel
// nuevo — no son el mismo concepto, valores por defecto iguales por
// coincidencia de negocio, no por ser el mismo campo). Mismo patrón de
// retrofit ya usado en esta tabla singleton (ALTER con default, sin
// re-insertar la fila).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->unsignedTinyInteger('edad_max_infante_gratis_hotel_default')->default(4)
                ->after('margen_minimo_aceptable_pct');
            $table->unsignedTinyInteger('edad_max_nino_cama_adicional_hotel_default')->default(12)
                ->after('edad_max_infante_gratis_hotel_default');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->dropColumn(['edad_max_infante_gratis_hotel_default', 'edad_max_nino_cama_adicional_hotel_default']);
        });
    }
};
