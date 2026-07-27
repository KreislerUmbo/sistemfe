<?php

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §3.12.
// Devolución de pagos por anulación con mercadería devuelta y retención
// parcial (gastos operativos). Distinto de anular un pago erróneo (§3.6):
// acá el pago fue correcto en su momento, lo que cambia es que la venta ya
// no se sostiene.
//
// Creada ANTES que payment_applications (aunque el plan la documenta en
// §3.12, después de §2.4) porque payment_applications.refund_id necesita
// esta tabla ya creada para tener FK real en vez de diferir la constraint.
//
// autorizado_por sin FK real a users (misma convención del proyecto) pero
// NOT NULL: el plan exige permiso elevado para autorizar, siempre debe
// haber alguien identificado.
//
// SIN softDeletes() a propósito: una devolución es un movimiento de
// dinero real, no debe poder desaparecer con un ->delete() sin dejar
// rastro.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sale_id');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('restrict');

            $table->decimal('monto_pagado_total', 12, 2);
            $table->decimal('monto_retenido', 12, 2)->default(0);
            $table->text('motivo_retencion')->nullable();
            $table->decimal('monto_devuelto', 12, 2);

            $table->string('medio_devolucion', 100);
            $table->string('nro_operacion_devolucion')->nullable();
            $table->date('fecha_devolucion');

            $table->unsignedBigInteger('autorizado_por');

            $table->enum('estado', ['pendiente', 'completado'])->default('pendiente');

            $table->timestamps();

            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
