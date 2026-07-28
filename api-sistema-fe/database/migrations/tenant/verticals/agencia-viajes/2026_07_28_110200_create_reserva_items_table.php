<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_110200_create_reserva_items_table.php
//
// Sesión 8a del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §4: copiados de la alternativa aceptada, pero con fecha y hora CONCRETAS
// de cada servicio (en cotización no siempre se sabe la hora exacta
// todavía).
//
// alternativa_item_id: FK real a alternativa_items (Sesión 7a) — costo/
// precio se leen de ahí, NO se duplican columnas de precio en esta tabla.
//
// guia_id: nullable, FK real a guias (Sesión 2) — se asigna normalmente un
// día antes del viaje (§4.2), debe poder quedar vacío sin bloquear el
// resto del flujo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->constrained('reserva');
            $table->foreignId('alternativa_item_id')->constrained('alternativa_items');
            $table->date('fecha');
            $table->time('hora')->nullable(); // heredada del tour, editable si ese día cambia
            $table->foreignId('guia_id')->nullable()->constrained('guias');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_items');
    }
};
