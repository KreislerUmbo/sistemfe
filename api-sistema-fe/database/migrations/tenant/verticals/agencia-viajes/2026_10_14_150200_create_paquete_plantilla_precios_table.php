<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_14_150200_create_paquete_plantilla_precios_table.php
//
// Fila 13, fase 1a (plan-tours-venta-simple-costo-real.md §3.12, N16): precio
// del tour por temporada. El precio base (columnas de paquetes_plantilla)
// aplica cuando ninguna temporada cubre la fecha. temporada_id apunta al
// catálogo CENTRAL `temporadas` (sin FK real cross-DB, mismo criterio que
// proveedor_tarifas.temporada_id). Una fila por tour y temporada.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paquete_plantilla_precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paquete_plantilla_id')->constrained('paquetes_plantilla')->cascadeOnDelete();
            $table->unsignedBigInteger('temporada_id'); // temporadas.id (central) — sin FK real cross-DB
            $table->decimal('precio_venta_adulto', 10, 2);
            $table->decimal('precio_venta_nino', 10, 2)->nullable(); // vacío = adulto
            $table->decimal('precio_venta_adulto_ext', 10, 2)->nullable(); // vacío = nacional
            $table->decimal('precio_venta_nino_ext', 10, 2)->nullable();
            $table->decimal('costo_estimado_persona', 10, 2)->nullable(); // vacío = el del tour
            $table->decimal('costo_estimado_persona_ext', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['paquete_plantilla_id', 'temporada_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paquete_plantilla_precios');
    }
};
