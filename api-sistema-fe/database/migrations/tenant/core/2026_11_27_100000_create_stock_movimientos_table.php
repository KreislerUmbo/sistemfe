<?php

// T-153 — Kárdex: cada movimiento de stock queda registrado. La tabla arranca con una fila
// «saldo inicial» por producto que controla stock (mismo criterio que Product::controlaStock()),
// incluidos los eliminados (una venta suya todavía puede devolverles stock). El historial
// anterior no se reconstruye.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->string('tipo', 20);
            $table->decimal('cantidad', 18, 6); // con signo, en unidad base: + entra, − sale
            $table->decimal('costo_unitario', 18, 6)->nullable(); // lo llena T-154
            $table->decimal('saldo', 18, 6); // stock del producto después del movimiento
            // Documento de origen, sin FK polimórfica: 'venta' (sales.id) o 'nota' (notes.id).
            $table->string('documento_tipo', 20)->nullable();
            $table->unsignedBigInteger('documento_id')->nullable();
            $table->string('motivo', 255)->nullable(); // obligatorio en ajustes
            $table->unsignedBigInteger('user_id')->nullable(); // null: lo creó esta migración
            $table->timestamp('created_at'); // UTC

            $table->index(['product_id', 'id']);
        });

        DB::insert(
            "insert into stock_movimientos (product_id, tipo, cantidad, saldo, motivo, created_at)
             select id, 'ajuste', stock, stock, 'saldo inicial', ?
             from products
             where tipo_bien_servicio is distinct from 'SERVICIO'
               and disponiblidad is distinct from 1
               and controla_stock = true
             order by id",
            [now()]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movimientos');
    }
};
