<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_100400_add_opcion_mayorista_foreign_to_opciones_hotel_table.php
//
// Sesión 7b del vertical Agencia de Viajes — cierra la última mitad de la FK
// diferida de opciones_hotel (Sesión 5, migración
// 2026_07_27_190300_create_opciones_hotel_table.php): paquete_plantilla_id
// ya se cerró en Sesión 6
// (2026_07_27_200300_add_paquete_plantilla_foreign_to_opciones_hotel_table.php),
// opcion_mayorista_id quedaba pendiente porque esa tabla no existía todavía
// — ahora sí (ver 2026_07_28_100100 en esta misma sesión). Ver TODO.md,
// sección Sesión 5 — queda marcado RESUELTO con esta migración.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->foreign('opcion_mayorista_id')->references('id')->on('opcion_mayorista');
        });
    }

    public function down(): void
    {
        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->dropForeign(['opcion_mayorista_id']);
        });
    }
};
