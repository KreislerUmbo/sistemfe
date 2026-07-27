<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_170100_create_destino_servicio_table.php
//
// Sesión 3 del vertical Agencia de Viajes — plan-modulo-tours-catalogo.md
// §4. Tabla puente entre destinos_atractivos y servicios (ambas tenant,
// creadas en Sesión 2) — FK reales, misma DB. destino_atractivo_id puede
// apuntar a CUALQUIER nivel del árbol (zona/lugar/atractivo, ej. el
// transporte se cotiza a nivel lugar pero las entradas a nivel atractivo)
// — no se restringe por tipo a nivel de schema, es una decisión de negocio
// al cargar el dato.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destino_servicio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destino_atractivo_id')->constrained('destinos_atractivos');
            $table->foreignId('servicio_id')->constrained('servicios');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destino_servicio');
    }
};
