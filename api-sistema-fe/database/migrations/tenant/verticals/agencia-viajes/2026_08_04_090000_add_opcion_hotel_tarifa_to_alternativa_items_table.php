<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_04_090000_add_opcion_hotel_tarifa_to_alternativa_items_table.php
//
// Sesión 11k — cablea la matriz de hoteles de paquete_plantilla al
// cotizador (hasta ahora solo opcion_mayorista tenía esta conexión, ver
// crearItemMayorista()). opcion_hotel_tarifa_id traza de qué tarifa de
// habitación salió el ítem (crearItemMayorista() no lo guardaba — hueco
// real, documentado, no repetido acá). paquete_plantilla_id es necesaria
// porque, a diferencia del hotel de mayorista (ya referenciado vía
// opcion_mayorista_id), acá no hay otra FK intermedia que conecte el
// ítem de vuelta a su paquete de origen.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->foreignId('opcion_hotel_tarifa_id')->nullable()
                ->after('opcion_mayorista_id')
                ->constrained('opciones_hotel_tarifas')->nullOnDelete();
            $table->foreignId('paquete_plantilla_id')->nullable()
                ->after('opcion_hotel_tarifa_id')
                ->constrained('paquetes_plantilla')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paquete_plantilla_id');
            $table->dropConstrainedForeignId('opcion_hotel_tarifa_id');
        });
    }
};
