<?php

// Módulo Créditos (plan §2 `credito_pagos`) — cada cobro. Nunca se edita ni se
// borra (1.9): solo referencia/observaciones son editables. fecha_pago = momento
// real del cobro, separado de created_at (sincronización, 1.14/1.22).
// destino_excedente usa los valores del motor (DestinoExcedente: devolver).
// es_cierre (12.12): si el pago cerró el crédito con el reparto de liquidación.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_pagos', function (Blueprint $table) {
            $table->comment('Pagos (cobros) recibidos por crédito.');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->string('numero_recibo', 20)->unique()->comment('Correlativo del recibo (RC-00000001).');
            $table->decimal('monto_recibido', 12, 2)->comment('Dinero recibido.');
            $table->decimal('monto_aplicado', 12, 2)->comment('Parte aplicada al crédito según el reparto.');
            $table->decimal('monto_excedente', 12, 2)->default(0)->comment('Parte no aplicada (devuelta o saldo a favor) (1.7).');
            $table->enum('destino_excedente', ['devolver', 'adelanto', 'saldo_a_favor'])->nullable()->comment('Qué se hizo con lo que superó lo exigible (1.7, 12.5).');
            $table->timestamp('fecha_pago')->comment('Momento real del cobro; define el orden del reparto (1.22).');
            $table->enum('origen', ['cobro', 'liquidacion', 'renovacion', 'venta_prenda', 'saldo_a_favor', 'saldo_inicial'])->default('cobro')->comment('Origen del pago.');
            $table->boolean('es_cierre')->default(false)->comment('true si el pago cerró el crédito con el reparto de liquidación (12.8, 12.12).');
            $table->foreignId('pagado_por_cliente_id')->nullable()->comment('Quién pagó: titular o garante (clients.id) (1.10).')->constrained('clients')->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->comment('Método de pago; null sin ingreso de caja (saldo_inicial, renovación, saldo a favor).')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->comment('Ingreso de caja del cobro (cash_movements.id).')->constrained('cash_movements')->restrictOnDelete();
            $table->string('referencia', 100)->nullable()->comment('Nº de operación (Yape, transferencia); editable y auditado.');
            $table->text('observaciones')->nullable()->comment('Notas; editables y auditadas.');
            $table->string('clave_idempotencia', 64)->unique()->comment('Generada por el cliente por intento de cobro; evita duplicados (5.2).');
            $table->enum('estado', ['valido', 'anulado'])->default('valido')->comment('Los pagos se anulan, nunca se borran (1.9).');
            $table->text('motivo_anulacion')->nullable()->comment('Motivo de la anulación.');
            $table->unsignedBigInteger('anulado_por')->nullable()->comment('users.id que anuló (sin FK).');
            $table->timestamp('anulado_en')->nullable()->comment('Momento de la anulación.');
            $table->unsignedBigInteger('registrado_por')->comment('users.id que registró el cobro (sin FK).');
            $table->timestamps();

            $table->index(['credito_id', 'estado']);
            $table->index(['fecha_pago', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_pagos');
    }
};
