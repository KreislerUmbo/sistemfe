<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_160000_create_destinos_atractivos_table.php
//
// Sesión 2 del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-tours-catalogo.md §2. Árbol autoreferenciado de 3 niveles
// (zona → lugar → atractivo) en una sola tabla — validado con casos reales
// (Full Day Alto Mayo, Tours Lamas Nativo). No todo destino necesita los 3
// niveles completos (ej. "Lamas" es tipo=lugar sin zona padre).
//
// Exclusiva del vertical: vive en tenant/verticals/agencia-viajes/, NO en
// tenant/core/ — un tenant retail no la necesita. Conexión tenant normal
// (sin $connection explícito, a diferencia de los catálogos centrales de
// Sesión 1).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinos_atractivos', function (Blueprint $table) {
            $table->id();
            // null = zona/raíz. Self-reference dentro del mismo Schema::create()
            // es válido en Postgres (la tabla ya existe para sí misma al momento
            // de aplicar el CREATE TABLE).
            $table->foreignId('parent_id')->nullable()->constrained('destinos_atractivos')->nullOnDelete();
            $table->string('nombre');
            $table->string('tipo'); // 'zona' | 'lugar' | 'atractivo'
            $table->text('descripcion')->nullable();
            $table->json('fotos')->nullable(); // array de paths, varias fotos por registro
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinos_atractivos');
    }
};
