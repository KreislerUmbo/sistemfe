<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_170200_create_proveedor_tipos_config_table.php
//
// Sesión 3 del vertical Agencia de Viajes — plan-modulo-proveedores.md
// §2.6. Qué tipos de proveedor usa cada agencia — deshabilitar un tipo
// solo oculta la opción al crear proveedores nuevos, nunca afecta
// proveedores ya existentes de ese tipo.
//
// proveedor_tipo_id referencia proveedor_tipos.id, que es CENTRAL
// (Sesión 1) — sin FK real cross-DB, mismo criterio que
// servicios.tipo_proveedor_id/proveedores.tipo_id.
//
// Se crea VACÍA a propósito en esta sesión — el sembrado automático al
// provisionar (copiar todo el catálogo central con habilitado=true) queda
// pendiente para otra sesión, ver TODO.md.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedor_tipos_config', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proveedor_tipo_id')->nullable(); // proveedor_tipos.id (central) — sin FK real cross-DB
            $table->boolean('habilitado')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_tipos_config');
    }
};
