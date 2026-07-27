<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_170000_create_proveedores_table.php
//
// Sesión 3 del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-proveedores.md §2.6. Schema validado por el negocio, usado
// tal cual (no se ajustó ningún tamaño/nullable).
//
// pais_id/departamento_id/provincia_id/distrito_id: SIN FK real — no existe
// catálogo ubigeo real en el backend todavía (solo un JSON de 25
// departamentos en admin-start-kit, sin provincias/distritos). Se resuelve
// cuando el catálogo ubigeo tenga su propia sesión.
//
// tipo_id: referencia proveedor_tipos.id (CENTRAL, Sesión 1) — sin FK real
// cross-DB, mismo criterio ya usado en servicios.tipo_proveedor_id
// (Sesión 2) y proveedor_tipos_config (esta misma sesión).
//
// margen_default_tipo/margen_default_valor: agregados 25-jul-2026
// (plan-modulo-proveedores.md, "Margen automático por mayorista") — precio
// de costo del mayorista sin proveedor_tarifa registrada, margen aplicado
// automático, editable línea por línea si la negociación puntual difiere.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique()->nullable();
            $table->string('razon_social', 250);
            $table->string('nombre_comercial', 250)->nullable();
            $table->string('tipo_persona', 20)->nullable();
            $table->string('tipo_documento', 20)->nullable();
            $table->string('numero_documento', 30)->nullable();
            $table->text('direccion')->nullable();
            $table->unsignedBigInteger('pais_id')->nullable();
            $table->unsignedBigInteger('departamento_id')->nullable();
            $table->unsignedBigInteger('provincia_id')->nullable();
            $table->unsignedBigInteger('distrito_id')->nullable();
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
            $table->text('observaciones')->nullable();
            $table->boolean('estado')->default(true);
            $table->unsignedBigInteger('tipo_id')->nullable(); // proveedor_tipos.id (central) — sin FK real cross-DB
            $table->string('margen_default_tipo')->nullable(); // 'porcentaje' | 'fijo'
            $table->decimal('margen_default_valor', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
