<?php

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4). No existía como tabla propia
// en el proyecto (confirmado por grep antes de escribir esta migración) —
// se crea tal como la pide el plan. cash_registers depende de esta tabla
// desde el día 1 (multi-sede), aunque el resto del negocio (products,
// sales, etc.) no la referencia todavía en esta fase.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
