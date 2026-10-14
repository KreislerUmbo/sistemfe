<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

// Permiso para enviar una cotización por correo desde el cotizador
// (plan-modulo-envio-correo-cotizacion.md §3.2). Solo crea el permiso: la
// asignación a los roles base de los tenants ya provisionados la hace
// `roles:sync-permisos-nuevos Database\\Seeders\\AgenciaViajesRolesSeeder`
// (nunca pisa personalizaciones manuales). La configuración del correo usa el
// permiso existente agencia.configuracion.
return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'cotizaciones.enviar_correo']);
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')->where('name', 'cotizaciones.enviar_correo')->delete();
    }
};
