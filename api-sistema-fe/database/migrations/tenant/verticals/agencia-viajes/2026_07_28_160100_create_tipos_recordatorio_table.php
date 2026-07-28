<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_160100_create_tipos_recordatorio_table.php
//
// Sesión 10 del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §8bis: catálogo de qué puede generar un recordatorio. TENANT (no central)
// aunque el set de códigos sea fijo hoy — es config propia de cada agencia
// (mismo criterio que reglas_cancelacion: catálogo con carga inicial vía
// seeder standalone, editable después por tenant), no un catálogo legal
// compartido como note_motivos/tax_configs.
//
// automatico=true en 4 de las 5 filas (el sistema las genera solo); solo
// 'personalizado' es false (alguien la crea a mano).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_recordatorio', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique(); // 'pago_proveedor_pendiente' | 'cumpleanos_cliente' | 'cotizacion_estancada' | 'documento_por_vencer' | 'personalizado'
            $table->string('nombre');
            $table->boolean('automatico');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_recordatorio');
    }
};
