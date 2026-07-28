<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_200300_add_paquete_plantilla_foreign_to_opciones_hotel_table.php
//
// Sesión 6 del vertical Agencia de Viajes — cierra la FK diferida de
// opciones_hotel.paquete_plantilla_id (Sesión 5, migración
// 2026_07_27_190300_create_opciones_hotel_table.php): la tabla
// paquetes_plantilla no existía todavía en esa sesión, ahora sí (ver
// 2026_07_27_200000 en esta misma sesión). Ver TODO.md, sección Sesión 5.
//
// opcion_mayorista_id NO se toca acá — esa tabla es Sesión 7, sigue
// pendiente, sin FK todavía.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->foreign('paquete_plantilla_id')->references('id')->on('paquetes_plantilla');
        });
    }

    public function down(): void
    {
        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->dropForeign(['paquete_plantilla_id']);
        });
    }
};
