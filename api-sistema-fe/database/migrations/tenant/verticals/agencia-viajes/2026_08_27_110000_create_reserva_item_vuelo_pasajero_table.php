<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_27_110000_create_reserva_item_vuelo_pasajero_table.php
//
// Corrige un bug real encontrado en pruebas en vivo (2026-08-27, mismo día
// de la migración anterior 2026_08_27_090000_add_vuelo_agencia_to_
// reserva_item_pasajero_table): guardar el vuelo de agencia como columnas
// de reserva_item_pasajero lo dejaba en la MISMA fila que edita el
// checkbox del tab "Asignación pasajero↔ítem" (esa tabla es la que decide
// agrupación de facturación/reporte, un concepto sin ninguna relación con
// el vuelo). Consecuencia real: desmarcar un pasajero en Asignación borraba
// la fila entera — incluido el vuelo de agencia ya cargado — y marcar
// checkboxes ahí podía hacer desaparecer sin querer el bloque de vuelo de
// pasajeros que seguían siendo del mismo vuelo.
//
// Fix: tabla propia, sin ninguna relación con reserva_item_pasajero ni con
// el checkbox de Asignación. Cada fila es independiente por (reserva_item,
// reserva_pasajero) — sin necesidad de "materializar a todos los
// pasajeros al primer edit" como sí requería compartir la tabla de
// checkin (ese truco existía solo para no romper el criterio "aplica a
// todos" de esa tabla compartida; acá no aplica, esta tabla es de uso
// exclusivo del vuelo).
//
// Backfill: cualquier dato de vuelo ya cargado en reserva_item_pasajero
// (sesión de pruebas en vivo del mismo día) se copia a la tabla nueva
// antes de borrar las columnas viejas — no se descarta silenciosamente.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_item_vuelo_pasajero', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_item_id')->constrained('reserva_items');
            $table->foreignId('reserva_pasajero_id')->constrained('reserva_pasajeros');
            $table->string('vuelo_numero_ida')->nullable();
            $table->date('vuelo_fecha_ida')->nullable();
            $table->time('vuelo_hora_ida')->nullable();
            $table->string('vuelo_numero_vuelta')->nullable();
            $table->date('vuelo_fecha_vuelta')->nullable();
            $table->time('vuelo_hora_vuelta')->nullable();
            // Si queda vacía, se asume la aerolínea tentativa cotizada en
            // CotizacionPasajeAereo — ver ReservaItemPasajeroController.
            $table->string('vuelo_aerolinea_confirmada')->nullable();
            $table->timestamps();
            $table->unique(['reserva_item_id', 'reserva_pasajero_id']);
        });

        $this->backfillDesdeReservaItemPasajero();

        Schema::table('reserva_item_pasajero', function (Blueprint $table) {
            $table->dropColumn([
                'vuelo_numero_ida', 'vuelo_fecha_ida', 'vuelo_hora_ida',
                'vuelo_numero_vuelta', 'vuelo_fecha_vuelta', 'vuelo_hora_vuelta',
                'vuelo_aerolinea_confirmada',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('reserva_item_pasajero', function (Blueprint $table) {
            $table->string('vuelo_numero_ida')->nullable();
            $table->date('vuelo_fecha_ida')->nullable();
            $table->time('vuelo_hora_ida')->nullable();
            $table->string('vuelo_numero_vuelta')->nullable();
            $table->date('vuelo_fecha_vuelta')->nullable();
            $table->time('vuelo_hora_vuelta')->nullable();
            $table->string('vuelo_aerolinea_confirmada')->nullable();
        });

        Schema::dropIfExists('reserva_item_vuelo_pasajero');
    }

    private function backfillDesdeReservaItemPasajero(): void
    {
        $filas = DB::table('reserva_item_pasajero')
            ->whereNotNull('vuelo_numero_ida')
            ->orWhereNotNull('vuelo_numero_vuelta')
            ->get();

        foreach ($filas as $fila) {
            DB::table('reserva_item_vuelo_pasajero')->insert([
                'reserva_item_id' => $fila->reserva_item_id,
                'reserva_pasajero_id' => $fila->reserva_pasajero_id,
                'vuelo_numero_ida' => $fila->vuelo_numero_ida,
                'vuelo_fecha_ida' => $fila->vuelo_fecha_ida,
                'vuelo_hora_ida' => $fila->vuelo_hora_ida,
                'vuelo_numero_vuelta' => $fila->vuelo_numero_vuelta,
                'vuelo_fecha_vuelta' => $fila->vuelo_fecha_vuelta,
                'vuelo_hora_vuelta' => $fila->vuelo_hora_vuelta,
                'vuelo_aerolinea_confirmada' => $fila->vuelo_aerolinea_confirmada,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
