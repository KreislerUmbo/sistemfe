<?php

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §2.5.
// Default de mora a nivel empresa; cada venta puede sobreescribirlo
// (ver sales.aplica_mora/tasa_mora/tipo_mora, migración
// 2026_07_15_100000). tasa_mora_default y tipo_mora_default nullable:
// una empresa que nunca configuró mora no debería forzar un 0/valor
// arbitrario, solo ausencia de config.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('mora_habilitada_default')->default(false);
            $table->decimal('tasa_mora_default', 10, 4)->nullable();
            $table->enum('tipo_mora_default', ['fijo_por_cuota', 'porcentaje_diario', 'porcentaje_fijo_unico'])
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['mora_habilitada_default', 'tasa_mora_default', 'tipo_mora_default']);
        });
    }
};
