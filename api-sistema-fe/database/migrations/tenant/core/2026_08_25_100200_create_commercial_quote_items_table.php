<?php
// Ítems de una cotización comercial. Deliberadamente SIN ningún campo de
// IGV/impuestos — garantía estructural de que este módulo nunca puede
// calcular tributos por accidente al reusar código de sale_details.
// product_id nullable: un ítem puede ser una línea libre (solo
// description) para algo que todavía no existe en el catálogo.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_quote_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('commercial_quote_id');
            $table->foreign('commercial_quote_id')->references('id')->on('commercial_quotes')->onDelete('cascade');

            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');

            // Obligatoria si product_id es null; si no, se copia del
            // producto al momento de agregar (igual que sale_details).
            $table->string('description');
            $table->string('unidad_medida', 25)->nullable();

            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2);  // congelado, nunca se recalcula desde catálogo
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('subtotal', 12, 2);     // SIN IGV

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_quote_items');
    }
};
