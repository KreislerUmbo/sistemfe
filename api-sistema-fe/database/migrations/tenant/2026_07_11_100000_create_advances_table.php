<?php
// database/migrations/2026_07_11_100000_create_advances_table.php
//
// Adelanto (anticipo) de cliente. sale_id apunta a la venta con
// type='advance' que es, a la vez, el comprobante SUNAT del adelanto
// (tipo de operación 0104, Catálogo 51 — ver GreenterService::getInvoice()).
// El adelanto SIEMPRE nace con su propio comprobante: la obligación del
// IGV nace al percibir el pago (art. 5.1 R.S. 007-99/SUNAT), así que
// sale_id no es nullable.
//
// applied_amount y refunded_amount son cacheados: se actualizan de forma
// atómica junto con las filas de advance_applications/advance_refunds
// (nunca releídos por SUM() en caliente). available_balance = amount -
// applied_amount - refunded_amount se calcula en el modelo, no es columna.
// Invariante aplicado a nivel de aplicación (no hay CHECK de BD en este
// proyecto, ver convención en create_notes_table.php): applied_amount +
// refunded_amount <= amount.
//
// client_id sin FK real seguirá la convención ya usada en notes/note_details
// para relaciones que no son sales/advances (clients no tiene migración de
// creación en este proyecto — ver memoria "schema-migrations-drift").

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advances', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('client_id');
            $table->index('client_id');

            $table->unsignedBigInteger('sale_id');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('restrict');

            $table->decimal('amount', 12, 2);
            $table->decimal('applied_amount', 12, 2)->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('PEN');

            $table->enum('status', [
                'pending',
                'partially_applied',
                'applied',
                'partially_refunded',
                'refunded',
            ])->default('pending');

            $table->string('payment_method');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advances');
    }
};
