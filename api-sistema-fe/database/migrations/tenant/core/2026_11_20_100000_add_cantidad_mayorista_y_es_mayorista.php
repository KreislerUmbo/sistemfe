<?php

// T-115 — Precio mayorista por cantidad o por cliente mayorista.
//
// `products.cantidad_mayorista`: desde cuántas unidades (sumando las líneas del producto en la
// venta) rige `price_company`, que pasa a ser el «precio mayorista». NULL = el producto no tiene
// mínimo: su precio mayorista solo lo reciben los clientes marcados como mayoristas.
// `clients.es_mayorista`: ese cliente siempre recibe el precio mayorista.
//
// Ningún dato se rellena a propósito: nadie queda mayorista y ningún producto queda con mínimo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cantidad_mayorista', 12, 3)->nullable();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('es_mayorista')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('es_mayorista');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cantidad_mayorista');
        });
    }
};
