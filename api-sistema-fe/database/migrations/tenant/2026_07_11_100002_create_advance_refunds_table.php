<?php
// database/migrations/2026_07_11_100002_create_advance_refunds_table.php
//
// Reembolso (parcial o total) del saldo de un adelanto, vía Nota de Crédito.
// note_id referencia notes.id (App\Models\Sale\Note), NO sales.id: las NC/ND
// de este proyecto viven en su propia tabla, separada de sales a propósito
// (ver comentario en create_notes_table.php). El plan original de este
// módulo asumía "credit_note_sale_id → sales"; se corrige aquí tras
// confirmar la estructura real.
//
// Motivo 06 (Devolución total, ya implementado en NotaElectronicaController)
// cubre el caso "adelanto nunca aplicado, se devuelve el 100%". Motivo 09
// (Disminución en el valor, para reembolso parcial de un adelanto ya
// aplicado en parte) queda pendiente de otra sesión — ver plan sección 0 y
// 3. Esta tabla no requiere cambios cuando esa sesión se implemente.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advance_refunds', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('advance_id');
            $table->foreign('advance_id')->references('id')->on('advances')->onDelete('restrict');

            $table->unsignedBigInteger('note_id');
            $table->foreign('note_id')->references('id')->on('notes')->onDelete('restrict');

            $table->decimal('amount_refunded', 12, 2);
            $table->text('reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('advance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_refunds');
    }
};
