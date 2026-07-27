<?php

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Catálogo tenant-scoped que
// reemplaza el <select> hardcodeado de register.vue/edit.vue. 'code' debe
// coincidir carácter por carácter con los valores que sales.payment_method
// ya usa hoy ("EFECTIVO", "TRANSFERENCIA", "YAPE", "PLIN",
// "TARJETA DE CREDITO" — ver PaymentMethodSeeder) para que las ventas
// históricas sigan comparando igual sin necesidad de migrar datos.
//
// 'is_active' ya cumple el rol de "soft delete" para este catálogo (desactivar
// no borra ni afecta ventas históricas, plan §3) — sin softDeletes() adicional,
// sería redundante con is_active.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
