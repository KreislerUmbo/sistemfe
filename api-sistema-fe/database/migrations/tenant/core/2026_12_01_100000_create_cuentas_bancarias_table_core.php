<?php

// T-136 (plan-tours-venta-simple-costo-real.md §5c C2 y C8) — `cuentas_bancarias` pasa al sistema
// base: los pagos a proveedor por banco la necesitan en todos los giros.
//
// Solo crea la tabla donde no existe (tiendas, créditos y agencias nuevas). En las agencias ya
// provisionadas la creó la migración del vertical, que ahora también se salta si la tabla ya está.
// `moneda` y cualquier columna nueva van en migraciones de core/.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cuentas_bancarias')) {
            return;
        }

        Schema::create('cuentas_bancarias', function (Blueprint $table) {
            $table->id();
            $table->string('banco');
            $table->string('titular');
            $table->string('numero_cuenta');
            $table->string('cci')->nullable();
            $table->string('alias')->nullable(); // ej. "Yape/Plin"
            $table->boolean('activo')->default(true);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // En una agencia la usa el PDF de cotización y puede ser anterior a esta migración.
        if (Schema::hasTable('configuracion_agencia')) {
            return;
        }

        Schema::dropIfExists('cuentas_bancarias');
    }
};
