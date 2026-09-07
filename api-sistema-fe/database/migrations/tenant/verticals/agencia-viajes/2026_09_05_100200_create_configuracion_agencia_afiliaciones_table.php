<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_05_100200_create_configuracion_agencia_afiliaciones_table.php
//
// Mejora del PDF de cotización (plan-mejora-pdf-cotizacion-cliente.md §4.3)
// — tabla puente TENANT (a diferencia de afiliaciones_turismo, que es el
// catálogo central): qué afiliaciones marcó ESTA agencia + su propio
// número de registro. No hay FK real hacia afiliaciones_turismo (vive en
// la base central, sin FK de Postgres posible entre bases distintas —
// mismo patrón cross-boundary ya documentado en CLAUDE.md para
// codigo_detraccion/cod_motivo), afiliacion_id se valida en PHP contra el
// catálogo central en el controller.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_agencia_afiliaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuracion_agencia_pdf_id')->constrained('configuracion_agencia_pdf')->cascadeOnDelete();
            $table->unsignedBigInteger('afiliacion_id'); // FK lógica a afiliaciones_turismo (central), validada en PHP
            $table->string('numero_registro')->nullable();
            $table->timestamps();
            // Nombre explícito y corto — el autogenerado (prefijo tabla+columnas)
            // colisiona con el de la FK de arriba al truncarse a 63 bytes,
            // el límite de un identificador en Postgres (encontrado corriendo
            // esta migración de verdad contra sistemafe_test_migrations).
            $table->unique(['configuracion_agencia_pdf_id', 'afiliacion_id'], 'config_agencia_afiliaciones_unicas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_agencia_afiliaciones');
    }
};
