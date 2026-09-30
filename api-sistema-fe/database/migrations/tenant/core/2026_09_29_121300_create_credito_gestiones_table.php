<?php

// Módulo Créditos (plan §2 `credito_gestiones`, 1.14) — visitas y resultados
// de cobranza; las promesas vencidas se muestran como alerta.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_gestiones', function (Blueprint $table) {
            $table->comment('Gestiones de cobranza por crédito.');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->unsignedBigInteger('cobrador_id')->comment('users.id que hizo la gestión (sin FK).');
            $table->timestamp('fecha_gestion')->comment('Momento de la visita/llamada.');
            $table->enum('resultado', ['pago', 'no_encontrado', 'promesa_pago', 'se_niega', 'otro'])->comment('Resultado de la gestión.');
            $table->date('fecha_promesa')->nullable()->comment('Fecha prometida de pago si resultado = promesa_pago.');
            $table->foreignId('pago_id')->nullable()->comment('Pago registrado en la gestión (credito_pagos.id).')->constrained('credito_pagos')->restrictOnDelete();
            $table->text('nota')->nullable()->comment('Detalle de la gestión.');
            $table->timestamps();

            $table->index(['credito_id', 'fecha_gestion']);
            $table->index(['resultado', 'fecha_promesa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_gestiones');
    }
};
