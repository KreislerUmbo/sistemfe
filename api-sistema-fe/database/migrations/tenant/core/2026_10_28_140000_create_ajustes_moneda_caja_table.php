<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// T-009 (28-oct-2026): registro de lo que cambia el comando caja:completar-moneda — cada
// movimiento de caja viejo que pasó de la moneda por defecto ('PEN') a la real de su
// documento, para poder revisarlo o devolverlo a mano.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_moneda_caja', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cash_movement_id');
            $table->string('moneda_anterior', 3);
            $table->string('moneda_nueva', 3);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_moneda_caja');
    }
};
