<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_110300_create_reserva_item_pasajero_table.php
//
// Sesión 8a del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §4: tabla puente — qué pasajero específico va en qué ítem/actividad
// (control real de quién hace qué, no todos los pax hacen todas las
// actividades).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_item_pasajero', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_item_id')->constrained('reserva_items');
            $table->foreignId('reserva_pasajero_id')->constrained('reserva_pasajeros');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_item_pasajero');
    }
};
