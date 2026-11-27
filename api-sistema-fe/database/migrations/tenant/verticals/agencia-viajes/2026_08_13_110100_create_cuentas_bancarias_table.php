<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T-136: en un tenant nuevo ya la creó core/, que corre antes (mismo caso que `proveedores`).
        if (Schema::hasTable('cuentas_bancarias')) {
            return;
        }

        Schema::create('cuentas_bancarias', function (Blueprint $table) {
            $table->id();
            $table->string('banco');
            $table->string('titular');
            $table->string('numero_cuenta');
            $table->string('cci')->nullable();
            $table->string('alias')->nullable(); // ej. "Yape/Plin"
            $table->boolean('activo')->default(true);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_bancarias');
    }
};
