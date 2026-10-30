<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_29_100100_add_reserva_pasajero_id_to_cotizacion_pasajeros.php
//
// T-002 (Modificar reserva): en una cotización de modificación, cada pasajero copiado
// recuerda a qué pasajero de la reserva representa. NULL = pasajero nuevo que el vendedor
// agregó en la modificación (T-003/T-005 lo usan para saber qué se agrega y qué se quita).
// Es el camino inverso de reserva_pasajeros.cotizacion_pasajero_id.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_pasajeros', function (Blueprint $table) {
            $table->foreignId('reserva_pasajero_id')->nullable()->after('cotizacion_id')
                ->constrained('reserva_pasajeros')->nullOnDelete()
                ->comment('Solo en cotizaciones de modificación: pasajero de la reserva que representa (NULL = nuevo)');
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_pasajeros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reserva_pasajero_id');
        });
    }
};
