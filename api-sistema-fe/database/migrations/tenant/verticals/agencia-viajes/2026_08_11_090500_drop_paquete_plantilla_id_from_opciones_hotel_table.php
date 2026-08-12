<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_11_090500_drop_paquete_plantilla_id_from_opciones_hotel_table.php
//
// Consolidación de hoteles — un hotel deja de poder atarse a un
// paquete_plantilla puntual (tab "Hoteles" de un combo/tour, eliminada):
// ahora es una tarifa más de proveedor_tarifas, buscable libremente en
// cualquier cotización. opciones_hotel/opciones_hotel_tarifas se mantienen
// exclusivamente para opcion_mayorista (paquetes internacionales con fecha
// fija) — opcion_mayorista_id NO se toca.
//
// Verificado antes de correr esta migración (obligatorio por instrucción
// explícita): 0 filas en opciones_hotel en TODOS los tenants reales
// existentes (agencia-demo es el único con la tabla — 0 filas totales,
// sandbox/umbo/negocio2/umbo-archivado ni siquiera tienen la tabla, giro
// retail) — sin dato real en juego.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->dropForeign(['paquete_plantilla_id']);
            $table->dropColumn('paquete_plantilla_id');
        });
    }

    public function down(): void
    {
        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->unsignedBigInteger('paquete_plantilla_id')->nullable()->after('opcion_mayorista_id');
            $table->foreign('paquete_plantilla_id')->references('id')->on('paquetes_plantilla');
        });
    }
};
