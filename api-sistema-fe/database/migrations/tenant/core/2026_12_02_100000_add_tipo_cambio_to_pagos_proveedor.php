<?php

// T-140 — pagos en dólares y en otra moneda. `pagos_proveedor.tipo_cambio` es el del pago (soles por
// dólar). En la aplicación, `monto` sigue en la moneda del pago (lo que sale de él) y `monto_compra`
// va en la moneda de la compra (lo que cancela); `tipo_cambio` solo cuando las monedas difieren.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos_proveedor', function (Blueprint $table) {
            $table->decimal('tipo_cambio', 10, 4)->nullable();
        });

        Schema::table('aplicaciones_pago_proveedor', function (Blueprint $table) {
            $table->decimal('monto_compra', 14, 2)->nullable();
            $table->decimal('tipo_cambio', 10, 4)->nullable();
        });

        // Hasta ahora solo se aplicaba en la misma moneda.
        DB::statement('update aplicaciones_pago_proveedor set monto_compra = monto');
        DB::statement('alter table aplicaciones_pago_proveedor alter column monto_compra set not null');
        DB::statement('alter table aplicaciones_pago_proveedor add constraint aplicaciones_pago_monto_compra_positivo check (monto_compra > 0)');
        DB::statement('alter table aplicaciones_pago_proveedor add constraint aplicaciones_pago_misma_moneda check (tipo_cambio is not null or monto_compra = monto)');
    }

    public function down(): void
    {
        DB::statement('alter table aplicaciones_pago_proveedor drop constraint aplicaciones_pago_misma_moneda');
        DB::statement('alter table aplicaciones_pago_proveedor drop constraint aplicaciones_pago_monto_compra_positivo');
        Schema::table('aplicaciones_pago_proveedor', fn (Blueprint $table) => $table->dropColumn(['monto_compra', 'tipo_cambio']));
        Schema::table('pagos_proveedor', fn (Blueprint $table) => $table->dropColumn('tipo_cambio'));
    }
};
