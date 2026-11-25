<?php

// T-133 — retiro de `suppliers`. Sus filas ya pasaron a `proveedores` en la migración anterior
// (2026_11_25_200100), que corre antes por nombre. Respaldar antes de desplegar.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('suppliers');
    }

    // Vuelve a crear la tabla vacía: los datos siguen en `proveedores`.
    public function down(): void
    {
        if (Schema::hasTable('suppliers')) {
            return;
        }

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('document')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
};
