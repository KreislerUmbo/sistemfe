<?php

// T-140 (D5) — dinero que el proveedor devuelve. Va aparte de `pagos_proveedor`, porque todo lo que
// lee esa tabla da por hecho que el dinero sale. Tiene un destino: la compra con saldo a favor (D6)
// o lo «a cuenta» de un pago (D2). Nunca se borra: se anula.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reembolsos_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->date('fecha'); // fecha de negocio (hora de Perú)
            $table->string('moneda', 3);
            $table->decimal('monto', 14, 2);
            $table->decimal('tipo_cambio', 10, 4)->nullable();

            $table->foreignId('compra_id')->nullable()->constrained('compras');
            $table->foreignId('pago_proveedor_id')->nullable()->constrained('pagos_proveedor');

            $table->string('origen', 20);
            $table->foreignId('cash_movement_id')->nullable()->constrained('cash_movements');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods');
            $table->foreignId('cuenta_bancaria_id')->nullable()->constrained('cuentas_bancarias');
            $table->string('numero_operacion', 30)->nullable();

            $table->string('estado', 25)->default('vigente'); // vigente | anulado
            $table->timestamp('anulado_at')->nullable(); // UTC
            $table->unsignedBigInteger('anulado_por')->nullable();
            $table->string('motivo_anulacion', 255)->nullable();
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index('proveedor_id');
            $table->index('compra_id');
            $table->index('pago_proveedor_id');
        });

        DB::statement('alter table reembolsos_proveedor add constraint reembolsos_proveedor_monto_positivo check (monto > 0)');
        DB::statement('alter table reembolsos_proveedor add constraint reembolsos_proveedor_un_destino check (num_nonnulls(compra_id, pago_proveedor_id) = 1)');
        DB::statement("alter table reembolsos_proveedor add constraint reembolsos_proveedor_caja_con_movimiento check (origen <> 'caja' or cash_movement_id is not null)");
        DB::statement("alter table reembolsos_proveedor add constraint reembolsos_proveedor_banco_con_operacion check (origen <> 'banco' or (cuenta_bancaria_id is not null and numero_operacion is not null))");
        DB::statement(
            'create unique index reembolsos_proveedor_operacion_unica on reembolsos_proveedor '
            ."(cuenta_bancaria_id, numero_operacion) where estado <> 'anulado'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reembolsos_proveedor');
    }
};
