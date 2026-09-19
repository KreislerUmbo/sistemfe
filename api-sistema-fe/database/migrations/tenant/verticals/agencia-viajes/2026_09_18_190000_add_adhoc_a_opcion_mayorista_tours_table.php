<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_18_190000_add_adhoc_a_opcion_mayorista_tours_table.php
//
// Tour ad-hoc de itinerario mayorista — hallazgo real del usuario: el
// mini-form de "Agregar tour nuevo" (TourIncluidoForm.vue) era la forma
// más rápida de armar el itinerario día-por-día de un paquete internacional
// para el PDF, pero forzaba crear un PaquetePlantilla NUEVO y PERMANENTE en
// el catálogo de Paquetes/Tours por cada "día" — incluyendo días que no son
// tours reales vendibles ("Arribo a Cusco", "Retorno"), solo logística de
// ESE itinerario puntual. Con el tiempo, cada destino nuevo cotizado
// mintaría su propio "Arribo a X"/"Retorno" suelto en el catálogo, sin
// ninguna forma de reusarlo — puro ruido creciente en el buscador de tours
// de CUALQUIER cotización futura.
//
// Mismo criterio que el hotel ad-hoc (opciones_hotel.proveedor_id nullable,
// Sesión M3): paquete_plantilla_id pasa a nullable — una fila con él en
// null es logística de ESTE itinerario únicamente, con su propio
// nombre/descripcion acá mismo, sin tocar el catálogo de tours para nada.
// Una fila con paquete_plantilla_id sigue siendo un tour real y reutilizable,
// sin cambios de comportamiento.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcion_mayorista_tours', function (Blueprint $table) {
            $table->string('nombre', 250)->nullable()->after('paquete_plantilla_id');
            $table->text('descripcion')->nullable()->after('nombre');
        });

        // DROP NOT NULL directo, no Blueprint::change() — este proyecto no
        // tiene doctrine/dbal instalado (Laravel 12 no lo exige para esto,
        // pero change() sobre un foreignId() con FK ya constreñida intenta
        // recrear la columna completa, incluida la FK, y choca con la que
        // ya existe). Una ALTER COLUMN puntual no toca la FK para nada —
        // un valor NULL simplemente no se valida contra ella en Postgres.
        DB::statement('ALTER TABLE opcion_mayorista_tours ALTER COLUMN paquete_plantilla_id DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('opcion_mayorista_tours', function (Blueprint $table) {
            $table->dropColumn(['nombre', 'descripcion']);
        });

        DB::statement('ALTER TABLE opcion_mayorista_tours ALTER COLUMN paquete_plantilla_id SET NOT NULL');
    }
};
