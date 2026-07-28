<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_160000_add_checkin_to_reserva_item_pasajero_table.php
//
// Sesión 10 del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-cotizaciones-reservas.md §8: reporte operativo por fecha. El
// reporte en sí NO es tabla nueva (es una vista/consulta sobre reserva_items
// + reserva_item_pasajero + reserva_pasajeros, filtrando reserva.estado !=
// cancelada) — solo hace falta que reserva_item_pasajero (Sesión 8a) tenga
// dónde guardar el check-in marcado desde la acción inline del reporte. La
// pantalla del reporte, el PDF, y las acciones inline (asignar guía, marcar
// check-in) son Sesión 11 (frontend/CRUD) — esta migración solo deja el dato
// listo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_item_pasajero', function (Blueprint $table) {
            $table->boolean('checkin_realizado')->default(false);
            $table->timestamp('checkin_hora')->nullable(); // se llena al marcar
        });
    }

    public function down(): void
    {
        Schema::table('reserva_item_pasajero', function (Blueprint $table) {
            $table->dropColumn(['checkin_realizado', 'checkin_hora']);
        });
    }
};
