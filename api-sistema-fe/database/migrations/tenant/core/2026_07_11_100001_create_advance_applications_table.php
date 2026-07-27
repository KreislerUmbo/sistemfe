<?php
// database/migrations/2026_07_11_100001_create_advance_applications_table.php
//
// Pivot: qué parte de qué adelanto se aplicó a qué venta final. amount_applied
// alimenta directamente PrepaidPayment/PaidAmount en el XML de esa venta (ver
// Greenter\Model\Sale\Prepayment::total, plan sección 0.5). No hace falta
// guardar serie/tipo de documento/RUC del adelanto aquí: esos datos ya están
// en el registro sales referenciado por advances.sale_id, así que armar el
// XML implica un join advance_applications → advances → sales.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advance_applications', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('advance_id');
            $table->foreign('advance_id')->references('id')->on('advances')->onDelete('restrict');

            $table->unsignedBigInteger('sale_id');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('restrict');

            $table->decimal('amount_applied', 12, 2);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['advance_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_applications');
    }
};
