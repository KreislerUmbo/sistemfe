<?php

// Módulo Créditos (plan §2 `credito_pago_aplicaciones`) — cómo se repartió cada
// pago. Al anular o reaplicar (1.9, 1.22) las filas anteriores quedan con
// vigente=false como historial y se insertan las nuevas con generacion + 1.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_pago_aplicaciones', function (Blueprint $table) {
            $table->comment('Reparto de cada pago entre cuotas y conceptos, por generación.');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito (creditos.id), para consultas por crédito.')->constrained('creditos')->restrictOnDelete();
            $table->foreignId('pago_id')->comment('Pago (credito_pagos.id).')->constrained('credito_pagos')->restrictOnDelete();
            $table->foreignId('cuota_id')->comment('Cuota (credito_cuotas.id).')->constrained('credito_cuotas')->restrictOnDelete();
            $table->enum('concepto', ['interes', 'capital', 'cargo', 'mora'])->comment('Concepto al que se aplicó el monto (1.7).');
            $table->decimal('monto', 12, 2)->comment('Monto aplicado.');
            $table->integer('generacion')->default(1)->comment('Número de reparto del crédito; sube en cada recálculo (1.9).');
            $table->boolean('vigente')->default(true)->comment('false = reparto reemplazado por un recálculo (historial).');
            $table->timestamps();

            $table->index(['credito_id', 'vigente']);
            $table->index(['cuota_id', 'vigente']);
            $table->index('pago_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_pago_aplicaciones');
    }
};
