<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_05_100300_add_portada_destacadas_to_paquetes_plantilla_table.php
//
// Mejora del PDF de cotización (plan-mejora-pdf-cotizacion-cliente.md §4.5)
// — portada del tour + hasta 4 destacadas para la galería del itinerario.
//
// Ajuste sobre el diseño original (Paso 0.1 del brief de ejecución):
// `paquetes_plantilla.fotos` es un array PLANO de rutas (string[]),
// consumido tal cual por 5 pantallas del frontend (TourIncluidoForm.vue,
// DestinoTreeSelect.vue, cotizador/editar.vue, destinos/form.vue,
// proveedores/detalle.vue). Cambiar la forma del array a objetos
// ({path, es_portada, ...}) hubiera roto las 5. En vez de eso, portada/
// destacadas se guardan en columnas NUEVAS que solo REFERENCIAN paths ya
// existentes en `fotos` — cero cambios a las pantallas ya construidas.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paquetes_plantilla', function (Blueprint $table) {
            $table->string('foto_portada')->nullable()->after('fotos'); // path, debe estar en `fotos`
            $table->json('fotos_destacadas_pdf')->nullable()->after('foto_portada'); // array de paths, máx. 4, deben estar en `fotos`
        });
    }

    public function down(): void
    {
        Schema::table('paquetes_plantilla', function (Blueprint $table) {
            $table->dropColumn(['foto_portada', 'fotos_destacadas_pdf']);
        });
    }
};
