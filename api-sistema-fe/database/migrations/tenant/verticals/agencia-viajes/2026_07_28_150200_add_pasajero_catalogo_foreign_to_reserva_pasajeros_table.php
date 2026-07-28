<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_150200_add_pasajero_catalogo_foreign_to_reserva_pasajeros_table.php
//
// Sesión 9c del vertical Agencia de Viajes — cierra la FK diferida de
// reserva_pasajeros.pasajero_catalogo_id (Sesión 8a, migración
// 2026_07_28_110100_create_reserva_pasajeros_table.php): la tabla
// pasajeros_catalogo no existía todavía en esa sesión, ahora sí (ver
// 2026_07_28_150000 en esta misma sesión). Mismo patrón de retrofit ya
// usado 4 veces en el vertical: paquete_plantilla_id (Sesión 6) y
// opcion_mayorista_id (Sesión 7b) sobre opciones_hotel, opcion_mayorista_id
// (Sesión 7b) sobre alternativa_items.
//
// Con esta migración NO queda ninguna FK diferida pendiente en todo el
// vertical Agencia de Viajes — ver TODO.md.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->foreign('pasajero_catalogo_id')->references('id')->on('pasajeros_catalogo');
        });
    }

    public function down(): void
    {
        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->dropForeign(['pasajero_catalogo_id']);
        });
    }
};
