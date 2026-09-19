<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_18_110000_add_reasignacion_hotel_a_reserva_items_table.php
//
// Reasignar hotel en una reserva ya aceptada, con auditoría — mismo
// mecanismo que ya existe para mayorista (2026_09_01_140000_add_opcion_
// mayorista_a_reserva_items_table.php), espejado para el hotel Local/
// Nacional. A diferencia de mayorista (una sola FK, opcion_mayorista_id),
// un hotel de este lado puede venir de 2 caminos distintos ya existentes
// en reserva_items (proveedor_tarifa_id o opcion_hotel_tarifa_id) — así
// que cada uno tiene su propia columna "original", pero la reasignación
// puede cruzar de un camino al otro (ver ReservaController::
// reasignarHotel(): al reasignar, la FK que no se usa queda en null).
//
// *_original_id: se escribe UNA sola vez, la primera vez que se reasigna
// — nunca se pisa en reasignaciones siguientes (mismo trade-off de
// auditoría simple que opcion_mayorista_original_id/
// fecha_viaje_desde_original).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->foreignId('proveedor_tarifa_original_id')->nullable()->after('opcion_hotel_tarifa_id')
                ->constrained('proveedor_tarifas')->nullOnDelete();
            $table->foreignId('opcion_hotel_tarifa_original_id')->nullable()->after('proveedor_tarifa_original_id')
                ->constrained('opciones_hotel_tarifas')->nullOnDelete();
            $table->text('motivo_reasignacion_hotel')->nullable()->after('opcion_hotel_tarifa_original_id');
            $table->timestamp('fecha_reasignacion_hotel')->nullable()->after('motivo_reasignacion_hotel');
            $table->unsignedInteger('veces_reasignado_hotel')->default(0)->after('fecha_reasignacion_hotel');
        });
    }

    public function down(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->dropColumn(['motivo_reasignacion_hotel', 'fecha_reasignacion_hotel', 'veces_reasignado_hotel']);
            $table->dropConstrainedForeignId('opcion_hotel_tarifa_original_id');
            $table->dropConstrainedForeignId('proveedor_tarifa_original_id');
        });
    }
};
