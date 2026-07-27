<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_160200_create_guias_table.php
//
// Sesión 2 del vertical Agencia de Viajes —
// plan-modulo-cotizaciones-reservas.md §5.3 / plan-modulo-maestros-iniciales.md
// §3-4. Catálogo simple de guías turísticos, sin manejo de disponibilidad/
// calendario (freelance, trabajan con varias agencias a la vez). Solo estos
// 4 campos — `guia_tarifas` (costo/margen por guía × destino × modalidad)
// es Sesión 5, no se toca acá.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('documento');
            $table->string('telefono');
            $table->boolean('activo')->default(true); // para desactivar sin borrar histórico
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guias');
    }
};
