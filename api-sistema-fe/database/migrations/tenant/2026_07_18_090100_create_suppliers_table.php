<?php

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Catálogo tenant-scoped de
// proveedores, usado desde el día 1 por el buscador de contraparte en
// egresos manuales (plan §6) y ampliable después por el módulo de Compras.
// Sin seed — tabla vacía, la llena el dueño del negocio.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('document')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
