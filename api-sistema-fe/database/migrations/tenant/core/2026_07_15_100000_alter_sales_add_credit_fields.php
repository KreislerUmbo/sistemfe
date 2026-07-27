<?php

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §2.1.
// 'condicion_pago' NO existía (verificado contra information_schema del
// tenant 'umbo' en dev, no asumido) — se crea aquí desde cero, default
// 'contado' para no dejar en NULL las ventas ya emitidas antes de este
// módulo.
//
// tasa_mora: decimal(10,4) porque la columna es dual-uso según tipo_mora —
// monto fijo en soles (fijo_por_cuota) o porcentaje como número entero
// (porcentaje_diario/porcentaje_fijo_unico, ej. 2.5000 = 2.5%, misma
// convención que 'porcentaje_igv'). La parte entera holgada evita chocar
// con un monto fijo de mora que supere una fracción pequeña.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('condicion_pago', ['contado', 'credito'])->default('contado');
            $table->enum('credit_type', ['cuotas_fijas', 'libre'])->nullable();
            $table->boolean('aplica_mora')->default(false);
            $table->decimal('tasa_mora', 10, 4)->nullable();
            $table->enum('tipo_mora', ['fijo_por_cuota', 'porcentaje_diario', 'porcentaje_fijo_unico'])
                ->nullable();
            $table->date('fecha_limite_pago')->nullable();
            $table->decimal('saldo_pendiente', 12, 2)->default(0);

            $table->unsignedBigInteger('replaces_sale_id')->nullable();
            $table->foreign('replaces_sale_id')->references('id')->on('sales')->onDelete('restrict');
            $table->index('replaces_sale_id');

            // Filtro central de cartera (§3.11) y del algoritmo FIFO (§3.4):
            // condicion_pago = 'credito' AND saldo_pendiente > 0.
            $table->index(['condicion_pago', 'saldo_pendiente']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['replaces_sale_id']);
            $table->dropColumn([
                'condicion_pago',
                'credit_type',
                'aplica_mora',
                'tasa_mora',
                'tipo_mora',
                'fecha_limite_pago',
                'saldo_pendiente',
                'replaces_sale_id',
            ]);
        });
    }
};
