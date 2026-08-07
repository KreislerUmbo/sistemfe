<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_06_090000_add_cama_adicional_nino_to_opciones_hotel_tables.php
//
// Sesión 11o — hoteles no tenían forma de reflejar cama adicional para
// niños según tramo de edad (toda habitación era un precio plano sin
// importar quién duerme ahí). edad_max_infante_gratis/
// edad_max_nino_cama_adicional viven a nivel de HOTEL (opciones_hotel, no
// configuracion_agencia) porque cada hotel puede tener su propia política
// — se precargan desde configuracion_agencia al crear un OpcionHotel nuevo
// (ver 2026_08_06_090100_...), pero quedan editables por hotel después.
// precio_costo/venta_cama_adicional viven por TARIFA (opciones_hotel_tarifas,
// no por hotel) porque el costo de una cama extra depende del tipo de
// habitación — nullable porque no toda habitación admite cama adicional.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->unsignedTinyInteger('edad_max_infante_gratis')->default(4)->after('moneda');
            $table->unsignedTinyInteger('edad_max_nino_cama_adicional')->default(12)->after('edad_max_infante_gratis');
        });

        Schema::table('opciones_hotel_tarifas', function (Blueprint $table) {
            $table->decimal('precio_costo_cama_adicional', 10, 2)->nullable()->after('precio_venta');
            $table->decimal('precio_venta_cama_adicional', 10, 2)->nullable()->after('precio_costo_cama_adicional');
        });
    }

    public function down(): void
    {
        Schema::table('opciones_hotel_tarifas', function (Blueprint $table) {
            $table->dropColumn(['precio_costo_cama_adicional', 'precio_venta_cama_adicional']);
        });

        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->dropColumn(['edad_max_infante_gratis', 'edad_max_nino_cama_adicional']);
        });
    }
};
