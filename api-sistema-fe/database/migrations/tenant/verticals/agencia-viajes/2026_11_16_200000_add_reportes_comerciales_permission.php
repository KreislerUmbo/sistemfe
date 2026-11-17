<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

// T-062 (CRM C6b) — permiso de la pantalla «Reportes comerciales». Solo crea el permiso: la
// asignación al rol Administrador de agencia de los tenants ya provisionados la hace
// `roles:sync-permisos-nuevos Database\\Seeders\\AgenciaViajesRolesSeeder` (nunca pisa
// personalizaciones) y el Propietario lo recibe con `roles:sync-propietario`. El ítem de menú lo
// agrega MenuItemsSeeder (deploy.sh paso 7).
return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'reportes_comerciales.ver']);
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')->where('name', 'reportes_comerciales.ver')->delete();
    }
};
