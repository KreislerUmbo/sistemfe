<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_29_190000_add_agencia_paquetes_permission.php
//
// Sesión 11b2 del vertical Agencia de Viajes — permiso nuevo para el CRUD
// de paquetes_plantilla (catálogo admin-level, mismo criterio que
// agencia.proveedores/agencia.destinos de Sesión 11a — no reusa
// agencia.cotizaciones porque armar/editar plantillas reutilizables es
// gestión de catálogo, no la operación diaria de cotizar).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'agencia.paquetes']);
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')->where('name', 'agencia.paquetes')->delete();
    }
};
