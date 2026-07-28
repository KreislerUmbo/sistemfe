<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_110100_create_reserva_pasajeros_table.php
//
// Sesión 8a del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §4: pasajero con datos completos (a diferencia de cotizacion_pasajeros,
// Sesión 7a, que solo cuenta por tipo/edad para calcular precio) — esta es
// la etapa donde se llenan los pasajeros reales que van a viajar, para
// control operativo al momento de realizar sus actividades.
//
// pasajero_catalogo_id: SIN FK todavía — pasajeros_catalogo es Sesión 9
// (roadmap fila 9), no existe aún. Queda como unsignedBigInteger nullable;
// la constraint real se agrega vía retrofit cuando esa sesión aterrice,
// mismo patrón ya usado 3 veces en el vertical (paquete_plantilla_id/
// opcion_mayorista_id sobre opciones_hotel, opcion_mayorista_id sobre
// alternativa_items).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_pasajeros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->constrained('reserva');
            $table->string('nombre');
            $table->string('documento');
            $table->string('nacionalidad')->nullable();
            $table->text('alimentacion_especial')->nullable();
            $table->text('discapacidad')->nullable();
            $table->string('vuelo_aerolinea_ida')->nullable();
            $table->time('vuelo_hora_ida')->nullable();
            $table->string('vuelo_aerolinea_vuelta')->nullable();
            $table->time('vuelo_hora_vuelta')->nullable();
            $table->unsignedBigInteger('pasajero_catalogo_id')->nullable(); // pasajeros_catalogo.id (Sesión 9) — sin FK todavía, tabla no existe
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_pasajeros');
    }
};
