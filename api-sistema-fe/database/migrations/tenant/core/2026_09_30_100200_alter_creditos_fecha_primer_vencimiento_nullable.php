<?php

// Módulo Créditos — Fase 3. creditos.fecha_primer_vencimiento guarda el primer
// vencimiento PEDIDO (null = automático, un intervalo después del desembolso), no
// la fecha ya ajustada de la cuota 1: el cronograma se regenera al activar y usa
// ese valor como ancla. Guardar la fecha ajustada movía el ancla (desembolso 31/01
// mensual: 28/02 → 28/03 en vez de 31/03). La fecha real de cada cuota vive en
// credito_cuotas.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->date('fecha_primer_vencimiento')->nullable()
                ->comment('Primer vencimiento pedido; null = automático (un intervalo tras el desembolso). La fecha real está en credito_cuotas.')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->date('fecha_primer_vencimiento')->nullable(false)->comment('Vencimiento de la cuota 1.')->change();
        });
    }
};
