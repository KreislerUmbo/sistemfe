<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// T-010: vuelto entregado al cobrar una venta (pago de más en efectivo), en su propia moneda.
// Informativo para la venta; el dinero que sale de caja queda en un cash_movement 'sale_change'.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('vuelto_monto', 12, 2)->nullable()->comment('Vuelto entregado al cobrar');
            $table->string('vuelto_moneda', 3)->nullable()->comment("Moneda del vuelto: 'PEN' | 'USD'");
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['vuelto_monto', 'vuelto_moneda']);
        });
    }
};
