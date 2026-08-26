<?php
// Cotizaciones comerciales — módulo nuevo e independiente de Sale, sin
// ningún efecto fiscal ni de stock. Reemplaza al retirado sales.state_sale
// (ver 2026_08_25_100000_drop_state_sale_from_sales_table.php): un
// presupuesto para negocios retail/ecommerce que controlan stock, sin
// relación con la entidad Cotizacion del vertical Agencia de Viajes
// (tabla `cotizaciones`, viajes — evitar cualquier colisión de nombre).
//
// client_id lleva FK real (a diferencia de advances.client_id): sales.
// client_id ya la tiene (create_sales_table.php) y clients SÍ tiene id
// real (create_clients_table.php, migración correctiva del drift de
// 2026-06-01) — no aplica acá la excepción que sí corresponde a
// advances/notes.
//
// converted_sale_id hace doble uso: referencia a la venta generada +
// guard anti-doble-conversión (unique nullable) — evita el estado
// inconsistente "convertida=true pero converted_sale_id=null" sin
// necesitar una columna booleana aparte.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_quotes', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20)->unique(); // "COT-00000001"

            $table->unsignedBigInteger('client_id')->nullable();
            $table->foreign('client_id')->references('id')->on('clients');
            $table->string('client_name_free')->nullable();
            $table->string('client_phone_free')->nullable();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users');

            $table->string('currency', 3)->default('PEN');

            $table->enum('status', [
                'borrador',
                'enviada',
                'aceptada',
                'rechazada',
                'vencida',
                'anulada',
            ])->default('borrador');

            $table->decimal('subtotal', 12, 2)->default(0);       // SIN IGV
            $table->decimal('discount_global', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);           // SIN IGV

            $table->date('valid_until')->nullable();
            $table->text('observacion')->nullable();

            $table->unsignedBigInteger('converted_sale_id')->nullable()->unique();
            $table->foreign('converted_sale_id')->references('id')->on('sales')->onDelete('set null');
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_quotes');
    }
};
