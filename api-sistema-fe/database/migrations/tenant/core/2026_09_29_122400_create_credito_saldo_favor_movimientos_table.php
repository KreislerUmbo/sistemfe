<?php

// Módulo Créditos (1.7) — historial del saldo a favor del cliente en créditos.
// clients.saldo_a_favor (Amortizaciones) es un número sin historial y se deja
// intacto; el saldo de créditos = suma de estos movimientos.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_saldo_favor_movimientos', function (Blueprint $table) {
            $table->comment('Movimientos del saldo a favor del cliente en el módulo de créditos.');
            $table->id();
            $table->foreignId('cliente_id')->comment('Cliente (clients.id).')->constrained('clients')->restrictOnDelete();
            $table->enum('tipo', ['abono', 'uso', 'devolucion', 'reverso'])->comment('abono = excedente guardado; uso = aplicado a un pago; devolucion = entregado en caja; reverso = por anulación.');
            $table->decimal('monto', 12, 2)->comment('Positivo suma al saldo, negativo lo resta.');
            $table->foreignId('pago_id')->nullable()->comment('Pago que originó o usó el saldo (credito_pagos.id).')->constrained('credito_pagos')->restrictOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->comment('Salida de caja si fue devolución.')->constrained('cash_movements')->restrictOnDelete();
            $table->text('motivo')->nullable()->comment('Detalle.');
            $table->unsignedBigInteger('registrado_por')->comment('users.id (sin FK).');
            $table->timestamps();

            $table->index('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_saldo_favor_movimientos');
    }
};
