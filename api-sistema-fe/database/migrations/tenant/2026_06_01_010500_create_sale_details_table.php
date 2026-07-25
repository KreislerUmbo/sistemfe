<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'sale_details' existe en Postgres pero nunca tuvo Schema::create en
// disco; solo existía el alter de 2026_07_03_125245 que agrega
// mto_valor_venta/mto_base_igv/porcentaje_igv/total_impuestos/tipo_isc/
// monto_isc_fijo — esas columnas NO se repiten aquí para no duplicarlas.
// Sin esta migración, ese alter falla en una base nueva (la tabla no
// existe todavía).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id'); // sin FK a nivel de BD en el real
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products');
            $table->unsignedBigInteger('product_categorie_id');
            $table->foreign('product_categorie_id')->references('id')->on('categories');
            $table->string('tip_afe_igv', 2)->nullable();
            $table->double('per_icbper')->default(0)->nullable();
            $table->double('icbper')->default(0)->nullable();
            $table->double('percentage_isc')->nullable();
            $table->double('isc')->nullable();
            $table->string('unidad_medida', 25)->nullable();
            $table->double('quantity')->nullable();
            $table->double('price_base')->nullable();
            $table->double('price_final')->nullable();
            $table->double('discount')->nullable();
            $table->double('subtotal')->nullable();
            $table->double('igv')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('product_id', 'idx_sale_details_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_details');
    }
};
