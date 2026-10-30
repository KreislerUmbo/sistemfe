<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_29_210000_add_penalidad_reserva_pasajero_id_to_alternativa_items.php
//
// T-004 (Modificar reserva, R-AGE-05, D-AGE-033): la penalidad por quitar un pasajero es un
// servicio manual más de la versión nueva; esta columna dice a qué pasajero de la reserva
// corresponde. NULL = ítem que no es una penalidad. Si el pasajero se borra de la reserva (al
// aplicar), el ítem queda como un servicio común.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->foreignId('penalidad_reserva_pasajero_id')->nullable()
                ->constrained('reserva_pasajeros')->nullOnDelete()
                ->comment('Pasajero de la reserva al que corresponde esta penalidad (solo en modificaciones de reserva)');
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('penalidad_reserva_pasajero_id');
        });
    }
};
