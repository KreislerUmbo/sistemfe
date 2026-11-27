<?php

// T-154 — líneas de producto (opcionales) de una compra: suman stock y actualizan el costo promedio.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras');
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('presentacion_id')->nullable(); // sin FK: todavía no existen
            $table->decimal('factor', 18, 6)->default(1);
            $table->decimal('cantidad', 18, 6);
            $table->decimal('valor_unitario', 18, 6); // sin IGV, en la moneda de la compra
            $table->decimal('valor_venta', 14, 2);
            $table->decimal('igv', 14, 2);
            $table->decimal('total', 14, 2);
            $table->decimal('costo_unitario_base', 18, 6); // en soles, por unidad base
            $table->timestamps();

            $table->index('compra_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_lineas');
    }
};
