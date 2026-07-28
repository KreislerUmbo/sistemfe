<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_100200_create_opcion_mayorista_opcionales_table.php
//
// Sesión 7b del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §2.4, "Tours opcionales": distinto de items_incluidos (que sí va en el
// precio base) — se muestran en el PDF como actividades que el cliente
// puede agregar, separadas del precio del paquete.
//
// Nunca se suman automáticamente al total (regla de aplicación, Sesión 11)
// — el cliente debe pedirlo explícito. No modelado acá, a propósito.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opcion_mayorista_opcionales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opcion_mayorista_id')->constrained('opcion_mayorista');
            $table->string('nombre'); // ej. "Excursión a San Blas"
            $table->decimal('precio_por_persona', 10, 2);
            $table->string('moneda');
            $table->text('incluye')->nullable();
            $table->text('no_incluye')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opcion_mayorista_opcionales');
    }
};
