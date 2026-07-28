<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_200100_create_tour_itinerario_items_table.php
//
// Sesión 6 del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §5.1. Itinerario a nivel de plantilla: por día RELATIVO (1, 2, 3...), no
// fecha calendario — el mismo tour se vende con distintas fechas de salida
// durante el año. destino_atractivo_id NULLABLE a propósito: pasos
// puramente de traslado/cierre sin atractivo específico (ej. "Retorno a
// Tarapoto" en el ejemplo real de Alto Mayo) — pendiente ya resuelto en
// plan-modulo-tours-catalogo.md §6.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_itinerario_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_id')->constrained('paquetes_plantilla');
            $table->unsignedSmallInteger('dia_relativo');
            $table->time('hora')->nullable(); // no todo tour trae hora por parada
            $table->unsignedSmallInteger('orden')->nullable(); // secuencia cuando no hay hora exacta
            $table->foreignId('destino_atractivo_id')->nullable()->constrained('destinos_atractivos');
            $table->text('descripcion');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_itinerario_items');
    }
};
