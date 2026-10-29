<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// T-009 (Caja, paso A): cada movimiento guarda la moneda real del dinero ('PEN' | 'USD').
// Antes un adelanto de US$ 1,000 entraba a la caja como si fueran S/ 1,000.
//
// Las filas existentes quedan en 'PEN' (default); el comando caja:completar-moneda pasa a
// 'USD' las que correspondan según su documento de origen.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->string('currency', 3)->default('PEN')->after('amount')
                ->comment("Moneda del dinero: 'PEN' | 'USD'");
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
