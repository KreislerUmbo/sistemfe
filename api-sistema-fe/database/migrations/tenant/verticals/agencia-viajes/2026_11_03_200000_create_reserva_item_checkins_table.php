<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_11_03_200000_create_reserva_item_checkins_table.php
//
// T-041 (H30): el check-in del reporte operativo sale de reserva_item_pasajero
// a su propia tabla. Esa tabla es la asignación pasajero↔servicio (sin filas =
// "para todos") y la usa la facturación por grupos; guardar ahí el check-in
// obligaba a crear asignaciones al marcarlo, y el servicio quedaba "pendiente:
// faltan pasajeros" al facturar. Mismo error que tuvo el vuelo de la agencia
// (2026_08_27_110000). Una fila = ese pasajero hizo check-in en ese servicio.
//
// La copia de los check-ins ya marcados va en la migración siguiente.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_item_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_item_id')->constrained('reserva_items')->cascadeOnDelete();
            $table->foreignId('reserva_pasajero_id')->constrained('reserva_pasajeros')->cascadeOnDelete();
            $table->timestamp('checkin_hora'); // instante en UTC
            $table->timestamps();
            // Contra el doble clic: un check-in por pasajero y servicio.
            $table->unique(['reserva_item_id', 'reserva_pasajero_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_item_checkins');
    }
};
