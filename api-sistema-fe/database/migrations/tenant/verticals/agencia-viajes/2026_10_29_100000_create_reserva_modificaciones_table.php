<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_29_100000_create_reserva_modificaciones_table.php
//
// T-002 (Modificar reserva, D-AGE-030): registro de cada modificación de una reserva.
// La modificación es una cotización propia (cotizaciones.origen = 'modificacion') con copia
// de los pasajeros y de la alternativa vigente; esta tabla las une a la reserva y guarda su
// ciclo de vida: abierta -> aplicada | descartada. Nunca se borra (queda como historial).
//
// R-AGE-01: una sola modificación abierta por reserva. Lo garantiza un índice único
// parcial de Postgres (mismo patrón que cash_sessions_one_open_per_register): la
// revisión en el servicio da el mensaje; el índice cubre la carrera entre dos pestañas.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_modificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->constrained('reserva')
                ->comment('Reserva que se modifica');
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')
                ->comment('Cotización de modificación (origen = modificacion) donde se edita la versión nueva');
            $table->foreignId('alternativa_vendida_id')->constrained('alternativas')
                ->comment('Alternativa vigente de la reserva al abrir la modificación (la versión vendida; no se edita)');
            $table->foreignId('alternativa_nueva_id')->constrained('alternativas')
                ->comment('Copia de la vendida, en la cotización de modificación, que el vendedor edita');
            $table->string('estado', 20)->default('abierta')
                ->comment('abierta | aplicada | descartada');
            $table->foreignId('creado_por')->constrained('users')
                ->comment('Usuario que abrió la modificación');
            $table->foreignId('aplicado_por')->nullable()->constrained('users')
                ->comment('Usuario que la aplicó a la reserva (T-005)');
            $table->timestamp('aplicado_en')->nullable()->comment('Instante en que se aplicó, UTC');
            $table->foreignId('descartado_por')->nullable()->constrained('users')
                ->comment('Usuario que la descartó');
            $table->timestamp('descartado_en')->nullable()->comment('Instante en que se descartó, UTC');
            $table->json('diferencia_aplicada')->nullable()
                ->comment('Diferencia con lo vendido tal como se aplicó, con penalidades (la llena T-005)');
            $table->timestamps();

            $table->index('reserva_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX reserva_modificaciones_una_abierta_por_reserva ' .
            "ON reserva_modificaciones (reserva_id) WHERE estado = 'abierta'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reserva_modificaciones_una_abierta_por_reserva');
        Schema::dropIfExists('reserva_modificaciones');
    }
};
