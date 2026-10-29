<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// T-010: monedas en que se acepta cada forma de pago. Por defecto soles y dólares; Yape y Plin
// (por código) solo existen en soles. Editable desde Formas de pago.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->json('monedas')->default('["PEN","USD"]')->comment("Monedas aceptadas: 'PEN' | 'USD'");
        });

        DB::table('payment_methods')->whereIn(DB::raw('UPPER(code)'), ['YAPE', 'PLIN'])->update(['monedas' => '["PEN"]']);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('monedas');
        });
    }
};
