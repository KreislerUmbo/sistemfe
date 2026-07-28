<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_110400_create_reserva_ventas_table.php
//
// Sesión 8a del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §4/§4.3/§4.4: tabla puente, NO un campo simple sale_id — una reserva
// puede terminar con más de una venta (servicio agregado después de
// facturada por "documento adicional", §4.3; o pagos por pasajero con
// varios responsables, §4.4). Ambos casos se resuelven con esta misma
// tabla, sin estructura nueva.
//
// sale_id: FK real a App\Models\Sale\Sale (core, tabla 'sales') — ya
// existe, tenant.
//
// reserva_item_ids/reserva_pasajero_ids: json, qué ítems/pasajeros cubre
// esta venta puntual — no FK real a un array (Postgres no lo soporta
// nativamente vía Eloquent de forma simple), se valida en aplicación que
// los IDs referenciados existan y pertenezcan a la misma reserva (Sesión
// 11, CRUD real).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->constrained('reserva');
            $table->foreignId('sale_id')->constrained('sales');
            $table->json('reserva_item_ids');
            $table->json('reserva_pasajero_ids');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_ventas');
    }
};
