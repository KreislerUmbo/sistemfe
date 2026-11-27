<?php

// T-154 (D1) — si el IGV de la compra va a crédito fiscal; define el costo de sus líneas.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->boolean('con_credito_fiscal')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn('con_credito_fiscal');
        });
    }
};
