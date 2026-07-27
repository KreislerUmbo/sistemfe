<?php

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4). Detalle físico opcional del
// arqueo (billetes/monedas contados al cierre). Sin lógica de negocio en
// esta fase.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_session_denominations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cash_session_id');
            $table->foreign('cash_session_id')->references('id')->on('cash_sessions')->onDelete('restrict');

            $table->decimal('denomination', 10, 2);
            $table->integer('quantity');
            $table->decimal('subtotal', 10, 2);

            $table->timestamps();

            $table->index('cash_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_session_denominations');
    }
};
