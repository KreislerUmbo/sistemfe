<?php

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §2.4.
// Pivot: a qué venta/cuota se aplicó cada parte de un payment_receipt.
// installment_id nullable porque solo aplica si la venta es 'cuotas_fijas'.
//
// estado 'trasladada' (§3.13, reemplazo de comprobante) y 'anulada' con
// refund_id (§3.12, liquidación por devolución) son casos distintos de
// anulación — 'anulada' también cubre revertir por error de caja (§3.6),
// donde refund_id queda null.
//
// origen_application_id es FK self-referencing dentro del mismo
// Schema::create: Postgres soporta self-reference en la misma sentencia
// porque Laravel emite la constraint como ALTER TABLE posterior al CREATE.
//
// SIN softDeletes() a propósito: esta es la tabla más sensible del módulo
// para cálculo de saldo. Un ->delete() accidental (o un bug) sobre una fila
// de payment_applications la sacaría en silencio de todos los cálculos de
// saldo_pendiente sin pasar por 'estado = anulada/trasladada', sin
// motivo, sin refund_id/origen_application_id — se pierde la trazabilidad
// que es el punto central del diseño de esta tabla (§2.4).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_applications', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('payment_receipt_id');
            $table->foreign('payment_receipt_id')->references('id')->on('payment_receipts')->onDelete('restrict');

            $table->unsignedBigInteger('sale_id');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('restrict');

            $table->unsignedBigInteger('installment_id')->nullable();
            $table->foreign('installment_id')->references('id')->on('installments')->onDelete('restrict');

            $table->decimal('monto_aplicado', 12, 2);
            $table->decimal('monto_mora_cobrado', 12, 2)->default(0);
            $table->unsignedSmallInteger('orden_aplicacion');

            $table->enum('estado', ['activo', 'anulada', 'trasladada'])->default('activo');

            $table->unsignedBigInteger('refund_id')->nullable();
            $table->foreign('refund_id')->references('id')->on('payment_refunds')->onDelete('restrict');

            $table->unsignedBigInteger('origen_application_id')->nullable();
            $table->foreign('origen_application_id')->references('id')->on('payment_applications')->onDelete('restrict');

            $table->timestamps();

            $table->index('payment_receipt_id');
            $table->index(['sale_id', 'installment_id']);
            $table->index(['sale_id', 'estado']);
            $table->index('refund_id');
            $table->index('origen_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_applications');
    }
};
