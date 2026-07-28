<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_100300_add_opcion_mayorista_foreign_to_alternativa_items_table.php
//
// Sesión 7b del vertical Agencia de Viajes — cierra la FK diferida de
// alternativa_items.opcion_mayorista_id (Sesión 7a, migración
// 2026_07_28_090400_create_alternativa_items_table.php): la tabla
// opcion_mayorista no existía todavía en esa sesión, ahora sí (ver
// 2026_07_28_100100 en esta misma sesión). Mismo patrón de retrofit que
// 2026_07_27_200300_add_paquete_plantilla_foreign_to_opciones_hotel_table.php
// (Sesión 6).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->foreign('opcion_mayorista_id')->references('id')->on('opcion_mayorista');
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropForeign(['opcion_mayorista_id']);
        });
    }
};
