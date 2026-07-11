<?php
// database/migrations/2026_07_10_090003_create_note_details_table.php
//
// Líneas de una Nota de Crédito/Débito. Deliberadamente compatible en
// nombres de columna con sale_details para que
// GreenterService::getDetallesComprobante() funcione sin cambios contra una
// colección de NoteDetail (ese método no tiene type hint, solo lee
// atributos por nombre).
//
// sale_detail_id es nullable: no-nulo cuando la línea corresponde a un
// ítem realmente vendido (devolución/descuento de algo ya vendido); nulo
// cuando es un concepto libre de ND usando un producto is_especial_nota=1
// (ej. "intereses por mora"), que no está ligado a ninguna venta ni afecta
// stock.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_details', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('note_id');
            $table->foreign('note_id')->references('id')->on('notes')->onDelete('cascade');

            $table->unsignedBigInteger('sale_detail_id')->nullable();
            $table->index('sale_detail_id');

            $table->unsignedBigInteger('product_id');
            $table->index('product_id');

            // ── Tasas/clasificación — heredadas tal cual de sale_details ─
            // cuando hay sale_detail_id (valor histórico de la venta,
            // nunca re-resuelto); tomadas del producto actual cuando es
            // línea libre de ND sin sale_detail_id. Ver plan, sección
            // "Modelo de datos".
            $table->string('tip_afe_igv', 2);
            $table->decimal('porcentaje_igv', 6, 2)->default(18);
            $table->string('tipo_isc', 2)->nullable();
            $table->decimal('percentage_isc', 10, 4)->default(0);
            $table->decimal('monto_isc_fijo', 10, 4)->default(0);
            $table->decimal('per_icbper', 10, 4)->default(0);

            // ── Montos de línea — copiados tal cual (nota total) o vía ──
            // NoteDetailAmountCalculator::prorratear() (nota parcial)
            $table->decimal('quantity', 12, 4);
            $table->decimal('price_base', 12, 6)->default(0);
            $table->decimal('price_final', 12, 6)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('igv', 12, 2)->default(0);
            $table->decimal('mto_valor_venta', 12, 6)->default(0);
            $table->decimal('mto_base_igv', 12, 6)->default(0);
            $table->decimal('total_impuestos', 12, 6)->default(0);
            $table->decimal('icbper', 12, 2)->default(0);
            $table->decimal('isc', 12, 2)->default(0);

            $table->string('unidad_medida', 10)->nullable();
            $table->string('description')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_details');
    }
};
