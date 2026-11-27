<?php

// T-136 (plan-tours-venta-simple-costo-real.md §3.7 «Pagos») — un solo modelo de pagos:
// `pagos_proveedor` es el dinero que sale, una vez; `aplicaciones_pago_proveedor`, cuánto de ese
// pago cancela qué compra. Un pago sin aplicar queda «a cuenta» del proveedor.
//
// `origen` no lleva enum ni CHECK de lista: agregar uno (detracción, retención, fondo a rendir) no
// pide migración; se valida en PHP contra PagoProveedor::ORIGENES.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->date('fecha'); // fecha de negocio (hora de Perú)
            $table->string('moneda', 3);
            $table->decimal('monto', 14, 2);

            $table->string('origen', 20);
            $table->foreignId('cash_movement_id')->nullable()->constrained('cash_movements');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods');
            $table->foreignId('cuenta_bancaria_id')->nullable()->constrained('cuentas_bancarias');
            $table->string('numero_operacion', 30)->nullable();

            $table->string('estado', 25)->default('vigente'); // pendiente_aprobacion | vigente | anulado
            $table->timestamp('anulado_at')->nullable(); // UTC
            $table->unsignedBigInteger('anulado_por')->nullable();
            $table->string('motivo_anulacion', 255)->nullable();
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index('proveedor_id');
            $table->index('fecha');
            $table->index('cash_movement_id');
        });

        DB::statement('alter table pagos_proveedor add constraint pagos_proveedor_monto_positivo check (monto > 0)');
        DB::statement("alter table pagos_proveedor add constraint pagos_proveedor_caja_con_movimiento check (origen <> 'caja' or cash_movement_id is not null)");
        DB::statement("alter table pagos_proveedor add constraint pagos_proveedor_banco_con_operacion check (origen <> 'banco' or (cuenta_bancaria_id is not null and numero_operacion is not null))");
        // El mismo número de operación de la misma cuenta no se registra dos veces.
        DB::statement(
            'create unique index pagos_proveedor_operacion_unica on pagos_proveedor '
            ."(cuenta_bancaria_id, numero_operacion) where estado <> 'anulado'"
        );

        // Nunca se borra: desaplicar es marcarla.
        Schema::create('aplicaciones_pago_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pago_proveedor_id')->constrained('pagos_proveedor');
            // Nullable: T-143 suma obligacion_id y cuota_id, y cambia el CHECK en su migración.
            $table->foreignId('compra_id')->nullable()->constrained('compras');
            $table->decimal('monto', 14, 2);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('desaplicada_at')->nullable(); // UTC
            $table->unsignedBigInteger('desaplicada_por')->nullable();
            $table->string('motivo_desaplicacion', 255)->nullable();
            $table->timestamps();

            $table->index('pago_proveedor_id');
            $table->index('compra_id');
        });

        DB::statement('alter table aplicaciones_pago_proveedor add constraint aplicaciones_pago_monto_positivo check (monto > 0)');
        DB::statement('alter table aplicaciones_pago_proveedor add constraint aplicaciones_pago_un_destino check (num_nonnulls(compra_id) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicaciones_pago_proveedor');
        Schema::dropIfExists('pagos_proveedor');
    }
};
