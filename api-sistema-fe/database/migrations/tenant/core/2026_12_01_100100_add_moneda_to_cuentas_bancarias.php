<?php

// T-136 — moneda de la cuenta. Las existentes quedan sin moneda: la pantalla pide completarla
// al usarlas para pagar (nunca se supone soles).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            $table->string('moneda', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            $table->dropColumn('moneda');
        });
    }
};
