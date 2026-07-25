<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'products' existe en Postgres pero nunca tuvo Schema::create en disco;
// solo existían alters puntuales (contenido_neto_litros, y un
// Schema::table('products') en create_detraction_codes_table.php que
// agrega una columna 'detraction_code' que NO EXISTE en la tabla real).
//
// OJO — ver reporte, punto de decisión: se deja fuera 'codigo_detraccion'
// a propósito. La columna real en Postgres se llama 'codigo_detraccion'
// (no 'detraction_code' como la nombra la migración vieja) y tiene FK a
// detraction_codes.codigo — pero esa tabla también tiene columnas muy
// distintas a lo que dice su migración (ver decisión #2 del reporte).
// Cuando definamos el esquema correcto de detraction_codes, agrego
// 'codigo_detraccion' + su FK en una migración aparte fechada después.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('title', 250);
            $table->string('sku', 50)->nullable();
            $table->unsignedBigInteger('categorie_id'); // sin FK a nivel de BD en el real
            $table->string('imagen', 250)->nullable();
            $table->double('price_general');
            $table->double('price_company')->nullable();
            $table->text('description')->nullable();
            $table->smallInteger('is_discount');
            $table->double('max_discount')->nullable();
            $table->smallInteger('disponiblidad');
            $table->smallInteger('state')->default(1);
            $table->string('unidad_medida', 25);
            $table->double('stock');
            $table->smallInteger('include_igv');
            $table->smallInteger('is_icbper');
            $table->smallInteger('is_ivap');
            $table->double('percentage_isc')->nullable();
            $table->smallInteger('is_especial_nota')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->string('tip_afe_igv_default', 50)->nullable();
            $table->string('tipo_bien_servicio', 20)->nullable();
            $table->boolean('aplica_percepcion')->nullable();
            $table->string('codigo_tipo_igv', 3)->default('20')->nullable();
            $table->boolean('is_isc')->nullable();
            $table->string('tipo_isc', 2)->nullable();
            $table->decimal('monto_isc_fijo', 10, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
