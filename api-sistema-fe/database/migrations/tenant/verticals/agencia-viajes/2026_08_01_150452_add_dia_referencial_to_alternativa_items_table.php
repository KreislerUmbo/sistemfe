<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_01_150452_add_dia_referencial_to_alternativa_items_table.php
//
// Sesión 11b3 (Parte A) — plan-modulo-cotizaciones-reservas.md §7.1: el lienzo
// "día por día" del cotizador nunca se implementó en 11b (lista plana hasta
// ahora). dia_referencial es el día ASIGNADO EN LA COTIZACIÓN concreta que se
// está armando — no confundir con tour_itinerario_items.dia_relativo (día
// dentro de la PLANTILLA de un tour, otra tabla, otro propósito). Nullable y
// sin tope contra duración del viaje (advisory, mismo criterio ya usado en el
// resto del proyecto para este tipo de validación).
//
// paquete_plantilla_items (el "Incluye" de un tour) no tiene ningún campo de
// día — decisión confirmada con el usuario: al explotar un tour_simple de
// varios días en el cotizador, TODOS sus ítems caen en un solo día_referencial
// (el día activo del vendedor al momento de cargarlo), no se distribuyen
// día por día dentro del tour. Un paquete_combo sí distribuye por tour-hijo
// (cada uno con su propio día de inicio), ver ComboExplosionService.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('dia_referencial')->nullable()->after('tour_origen_id');
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropColumn('dia_referencial');
        });
    }
};
