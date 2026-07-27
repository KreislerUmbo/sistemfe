<?php

// Módulo Caja — Fase 1 (plan-modulo-caja.md §10). 'companies' ya es el
// mecanismo de configuración por tenant que usa este proyecto (ver
// mora_habilitada_default/tasa_mora_default/tipo_mora_default en
// alter_companies_add_mora_defaults.php, Módulo Amortizaciones) — se agregan
// estas columnas ahí en vez de crear una tabla 'cash_settings' nueva, mismo
// patrón, sin tabla adicional.
//
// blind_close_default es el default que hereda cash_registers.blind_close
// cuando es null (Fase 1, Paso 2) — la resolución real (leer el default si
// la caja no tiene opinión propia) es lógica de Fase 2, no de aquí.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('blind_close_default')->default(false);
            $table->boolean('allow_multiple_registers_per_branch')->default(false);
            $table->decimal('difference_tolerance', 10, 2)->default(2.00);
            $table->boolean('require_expense_concept')->default(true);
            $table->boolean('require_expense_approval')->default(false);
            $table->decimal('max_expense_without_approval', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'blind_close_default',
                'allow_multiple_registers_per_branch',
                'difference_tolerance',
                'require_expense_concept',
                'require_expense_approval',
                'max_expense_without_approval',
            ]);
        });
    }
};
