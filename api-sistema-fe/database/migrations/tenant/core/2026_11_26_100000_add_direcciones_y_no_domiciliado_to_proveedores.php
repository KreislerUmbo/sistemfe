<?php
// database/migrations/tenant/core/2026_11_26_100000_add_direcciones_y_no_domiciliado_to_proveedores.php
//
// T-134 (plan-tours-venta-simple-costo-real.md §3.7 y §3.7c «Paso 1»).
// `direccion` sigue siendo la dirección del local; `direccion_fiscal` la llena la consulta RUC.
// `ciudad` es texto libre (T-083 quitó el ubigeo y no se crea un catálogo).
// Un proveedor no domiciliado usa tipo_documento/numero_documento para su documento del exterior.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->text('direccion_fiscal')->nullable();
            $table->string('ciudad', 120)->nullable();
            $table->boolean('es_no_domiciliado')->default(false);
            $table->string('pais', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn(['direccion_fiscal', 'ciudad', 'es_no_domiciliado', 'pais']);
        });
    }
};
