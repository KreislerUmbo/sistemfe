<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// T-152 — a qué reserva, salida o «general» va cada monto de una compra
// (plan-tours-venta-simple-costo-real.md §3.7). Solo giro Agencia (Q1); la tabla
// `compras` es de core/, que corre antes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_asignaciones', function (Blueprint $table) {
            $table->id();

            // Sin cascada: ni la compra, ni la reserva, ni la salida arrastran el reparto.
            $table->foreignId('compra_id')->constrained('compras');
            $table->string('destino', 10); // reserva | salida | general
            $table->foreignId('reserva_id')->nullable()->constrained('reserva');
            $table->foreignId('salida_operativa_id')->nullable()->constrained('salidas_operativas');
            $table->decimal('monto', 14, 2); // en la moneda de la compra
            $table->unsignedBigInteger('user_id')->nullable(); // quién ingresó la fila (Q12)
            $table->timestamps();

            $table->index('compra_id');
            $table->index('reserva_id');
            $table->index('salida_operativa_id');
        });

        DB::statement(<<<'SQL'
            alter table compra_asignaciones add constraint compra_asignaciones_destino_check check (
                (destino = 'reserva' and reserva_id is not null and salida_operativa_id is null)
                or (destino = 'salida' and salida_operativa_id is not null and reserva_id is null)
                or (destino = 'general' and reserva_id is null and salida_operativa_id is null)
            )
        SQL);
        DB::statement('alter table compra_asignaciones add constraint compra_asignaciones_monto_check check (monto > 0)');

        // Un mismo destino no se repite en una compra.
        DB::statement('create unique index compra_asignaciones_reserva_unica on compra_asignaciones (compra_id, reserva_id) where reserva_id is not null');
        DB::statement('create unique index compra_asignaciones_salida_unica on compra_asignaciones (compra_id, salida_operativa_id) where salida_operativa_id is not null');
        DB::statement("create unique index compra_asignaciones_general_unica on compra_asignaciones (compra_id) where destino = 'general'");
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_asignaciones');
    }
};
