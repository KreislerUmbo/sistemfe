<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_23_100000_add_cotizacion_pasajero_id_to_reserva_pasajeros.php
//
// F4 etapa 1 (23-oct-2026, auditoría de reserva + facturación): cada pasajero
// de la reserva guarda de qué pasajero de la cotización viene. Antes
// ReservaController::reconstruirMapaPasajeros() lo adivinaba por POSICIÓN
// (1.º con 1.º…), y desde que se puede quitar un pasajero en la reserva eso
// asignaba los servicios de "sincronizar" a otra persona (o a todos).
//
// Reservas existentes: se vinculan por posición SOLO si es seguro — misma
// cantidad de pasajeros y mismo tipo (adulto/niño/infante) en el mismo orden.
// Si alguien se agregó o se quitó, quedan sin vincular (NULL): sincronizar
// trata a esos pasajeros como si no vinieran de la cotización, en vez de
// adivinar. down() quita la columna (y con ella el vínculo).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->foreignId('cotizacion_pasajero_id')->nullable()->after('reserva_id')
                ->constrained('cotizacion_pasajeros')->nullOnDelete();
        });

        $reservas = DB::table('reserva')
            ->join('alternativas', 'alternativas.id', '=', 'reserva.alternativa_id')
            ->select('reserva.id', 'alternativas.cotizacion_id')
            ->get();

        foreach ($reservas as $reserva) {
            $deLaCotizacion = DB::table('cotizacion_pasajeros')
                ->where('cotizacion_id', $reserva->cotizacion_id)->orderBy('id')->get(['id', 'tipo_pax']);
            $deLaReserva = DB::table('reserva_pasajeros')
                ->where('reserva_id', $reserva->id)->orderBy('id')->get(['id', 'tipo_pax']);

            $mismoOrden = $deLaCotizacion->count() === $deLaReserva->count()
                && $deLaCotizacion->pluck('tipo_pax')->all() === $deLaReserva->pluck('tipo_pax')->all();
            if (! $mismoOrden) {
                continue;
            }

            foreach ($deLaReserva as $i => $pasajero) {
                DB::table('reserva_pasajeros')->where('id', $pasajero->id)
                    ->update(['cotizacion_pasajero_id' => $deLaCotizacion[$i]->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cotizacion_pasajero_id');
        });
    }
};
