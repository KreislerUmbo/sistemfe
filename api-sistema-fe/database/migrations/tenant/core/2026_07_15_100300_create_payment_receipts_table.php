<?php

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §2.3.
// Recibo de cobro interno (no regulado por SUNAT) — unifica el caso
// general (multi-venta) y el específico (§3.2/§3.3): un pago específico es
// 1 payment_receipts + 1 payment_applications; uno general es 1
// payment_receipts + N payment_applications.
//
// client_id CON FK real a clients (a diferencia del precedente en
// advances.client_id): se confirmó que 'clients' sí tiene Schema::create
// real y corre antes en el orden de migraciones, así que el FK da
// integridad real sin riesgo de orden.
//
// registrado_por sin FK real a users, misma convención que el resto del
// proyecto para columnas de auditoría de usuario.
//
// SIN softDeletes() a propósito: un recibo de pago nunca debe poder
// desaparecer con un ->delete() (por error o bug) sin pasar por
// 'estado = anulado' con motivo_anulacion/anulado_por — perderlo en
// silencio rompe todos los cálculos de saldo de las ventas que afectó.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();

            $table->string('numero_recibo', 20)->unique();

            $table->unsignedBigInteger('client_id');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('restrict');

            $table->date('fecha_pago');
            $table->string('medio_pago', 100);
            $table->string('nro_operacion')->nullable();

            $table->decimal('monto_total', 12, 2);
            $table->decimal('monto_no_aplicado', 12, 2)->default(0);

            $table->unsignedBigInteger('registrado_por');

            $table->enum('estado', ['activo', 'anulado'])->default('activo');
            $table->text('motivo_anulacion')->nullable();
            $table->unsignedBigInteger('anulado_por')->nullable();
            $table->timestamp('anulado_en')->nullable();

            $table->timestamps();

            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
