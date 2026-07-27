<?php

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4). Desnormalizado: uno por
// método de pago y sesión — evita recalcular sumando cash_movements en cada
// consulta del "corte X" (Fase 2/5). Sin lógica de negocio en esta fase: nada
// llena estas filas todavía, eso es de Fase 2 en adelante.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_session_totals', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cash_session_id');
            $table->foreign('cash_session_id')->references('id')->on('cash_sessions')->onDelete('restrict');

            $table->unsignedBigInteger('payment_method_id');
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->onDelete('restrict');

            $table->decimal('expected_amount', 10, 2)->default(0);
            $table->integer('movement_count')->default(0);

            $table->timestamps();

            $table->unique(['cash_session_id', 'payment_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_session_totals');
    }
};
