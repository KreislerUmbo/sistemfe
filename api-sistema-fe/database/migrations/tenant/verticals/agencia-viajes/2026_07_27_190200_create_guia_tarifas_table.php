<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_190200_create_guia_tarifas_table.php
//
// Sesión 5 del vertical Agencia de Viajes —
// plan-modulo-cotizaciones-reservas.md §5.3. El guía se paga por día, varía
// según destino y modalidad. Sin descuento_maximo_pct/margen_minimo_pct a
// propósito (explícitamente excluido del plan para guías, a diferencia de
// proveedor_tarifas).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guia_tarifas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guia_id')->constrained('guias');
            $table->foreignId('destino_id')->constrained('destinos_atractivos'); // zona o lugar, ej. "Alto Mayo" o "Cusco"
            $table->string('modalidad'); // 'dia_local' | 'grupo_multidia'
            $table->decimal('costo_diario', 10, 2);
            $table->string('tipo_margen'); // 'porcentaje' | 'fijo'
            $table->decimal('margen_valor', 10, 2);
            $table->string('moneda');
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guia_tarifas');
    }
};
