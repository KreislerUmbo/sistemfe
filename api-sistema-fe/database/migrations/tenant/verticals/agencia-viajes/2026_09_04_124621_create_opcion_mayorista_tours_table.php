<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_04_124621_create_opcion_mayorista_tours_table.php
//
// Tours incluidos con itinerario real para paquetes de mayorista — hallazgo real
// armando la simulación del paquete "Panamá 6D/5N" (docs/auxiliares/): "City Tour,
// Canal de Panamá" (narrativa completa, día por día) no tenía dónde vivir en el
// modelo de mayorista, que solo tenía opcion_mayorista.incluye (texto plano, sin
// días/horas). En vez de duplicar el modelo de itinerario, este vínculo reusa
// PaquetePlantilla + TourItinerarioItem (el mismo mecanismo ya probado para
// Local/Nacional) — 'orden' es el "Día" que ve el vendedor, y alimenta a
// AlternativaController::itinerarioAlternativa() con el mismo offset de días que
// ya encadena varios tours de un combo Local/Nacional.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opcion_mayorista_tours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opcion_mayorista_id')->constrained('opcion_mayorista');
            $table->foreignId('paquete_plantilla_id')->constrained('paquetes_plantilla');
            $table->integer('orden')->default(1);
            $table->timestamps();

            $table->unique(['opcion_mayorista_id', 'paquete_plantilla_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opcion_mayorista_tours');
    }
};
