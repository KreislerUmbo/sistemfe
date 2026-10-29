<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// T-012: un pago de venta puede ser un traspaso de lo ya cobrado en otro comprobante de la misma
// reserva (corregir con NC y volver a facturar). El traspaso es un `sale_payment` sin movimiento
// de caja: el dinero entró una sola vez, con el comprobante original.
//
// Pagos ya guardados: todos son cobros ('cobro' por defecto).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('origen', 10)->default('cobro')->comment("'cobro' (entró dinero) | 'traspaso' (viene de otra venta)");
            $table->unsignedBigInteger('traspasado_de_sale_id')->nullable()->comment('Venta de la que se traspasó (solo origen=traspaso)');
            $table->foreign('traspasado_de_sale_id')->references('id')->on('sales');
            $table->index('traspasado_de_sale_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropForeign(['traspasado_de_sale_id']);
            $table->dropIndex(['traspasado_de_sale_id']);
            $table->dropColumn(['origen', 'traspasado_de_sale_id']);
        });
    }
};
