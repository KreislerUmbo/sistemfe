<?php

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4). Cajas lógicas (no
// necesariamente hardware), una o más por sede. 'type' y ningún otro campo
// de esta tabla tiene CHECK a nivel de Postgres — mismo criterio que
// cash_concepts.direction en Fase 0, validación en la capa de aplicación.
//
// 'blind_close' nullable a propósito: null = hereda el default de
// tenant/empresa (companies.blind_close_default, ver Paso 6), no un booleano
// forzado — así una caja puede no tener opinión propia sobre el tema.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('restrict');

            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type')->default('fixed');
            $table->boolean('is_active')->default(true);
            $table->boolean('blind_close')->nullable();
            $table->decimal('default_opening_amount', 10, 2)->default(0);

            $table->timestamps();

            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
