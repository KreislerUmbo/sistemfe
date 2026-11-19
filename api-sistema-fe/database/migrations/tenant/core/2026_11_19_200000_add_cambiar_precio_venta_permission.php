<?php

// T-109 — Permiso para cambiar el precio de una línea en la venta. La validación real vive en
// SaleController (PrecioVentaValidator), no solo en la pantalla.
//
// Lo reciben los roles administradores que YA existan en el tenant (no se crean): hoy el rol
// Propietario no tiene usuarios (T-078) y `roles:sync-propietario` solo lo cubre a él. Vendedores,
// cajeros y demás no lo reciben. Nombres reales: «Administrador de agencia»
// (AgenciaViajesRolesSeeder), «Admin-General» (agencia-demo) y «Admin Empresario» (dakamu, hecho a
// mano). «Administrador de créditos» no: ese giro no vende por esta pantalla (sin register_sale).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISO = 'cambiar_precio_venta';
    private const ROLES_ADMINISTRADORES = ['Admin-General', 'Administrador de agencia', 'Admin Empresario'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permiso = Permission::firstOrCreate(['guard_name' => 'api', 'name' => self::PERMISO]);

        Role::where('guard_name', 'api')->whereIn('name', self::ROLES_ADMINISTRADORES)->get()
            ->each(fn (Role $rol) => $rol->hasPermissionTo($permiso) || $rol->givePermissionTo($permiso));
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')->where('name', self::PERMISO)->delete();
    }
};
