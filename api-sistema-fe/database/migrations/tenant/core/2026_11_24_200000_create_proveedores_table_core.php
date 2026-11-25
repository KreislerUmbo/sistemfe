<?php
// database/migrations/tenant/core/2026_11_24_200000_create_proveedores_table_core.php
//
// T-132 (plan-tours-venta-simple-costo-real.md §3.7c y §5c C8): `proveedores` pasa al sistema
// base, porque Compras, Caja y Rendiciones necesitan un solo catálogo en todos los giros.
//
// Solo crea la tabla donde no existe (tiendas, créditos y agencias nuevas). En las agencias ya
// provisionadas no hace nada: la creó la migración del vertical, que ahora también se salta si la
// tabla ya está. El esquema es el vigente de Agencia (con es_referencial, descripcion y fotos; sin
// las columnas de ubigeo que quitó T-083). Cualquier columna nueva va en migraciones de core/.
//
// tipo_id referencia proveedor_tipos (CENTRAL): sin FK real cross-DB.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('proveedores')) {
            return;
        }

        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique()->nullable();
            $table->string('razon_social', 250);
            $table->string('nombre_comercial', 250)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('tipo_persona', 20)->nullable();
            $table->string('tipo_documento', 20)->nullable();
            $table->string('numero_documento', 30)->nullable();
            $table->text('direccion')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('celular', 50)->nullable();
            $table->string('whatsapp', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('pagina_web', 250)->nullable();
            $table->string('facebook', 250)->nullable();
            $table->string('instagram', 250)->nullable();
            $table->string('tiktok', 250)->nullable();
            $table->string('linkedin', 250)->nullable();
            $table->string('logo', 300)->nullable();
            $table->json('fotos')->nullable();
            $table->text('observaciones')->nullable();
            $table->boolean('estado')->default(true);
            $table->boolean('es_referencial')->default(false);
            $table->unsignedBigInteger('tipo_id')->nullable(); // proveedor_tipos.id (central)
            $table->string('margen_default_tipo')->nullable(); // 'porcentaje' | 'fijo'
            $table->decimal('margen_default_valor', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // En una agencia la tabla la usan servicios, tarifas y hoteles: no se borra.
        if (Schema::hasTable('proveedor_servicios')) {
            return;
        }

        Schema::dropIfExists('proveedores');
    }
};
