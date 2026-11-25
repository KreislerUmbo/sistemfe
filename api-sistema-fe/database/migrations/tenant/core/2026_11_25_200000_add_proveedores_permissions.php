<?php

// T-133 — permisos del proveedor en el sistema base: proveedores.ver y proveedores.gestionar.
//
// Backfill: quien ya podía trabajar con proveedores sigue pudiendo, sin ganar de más.
//   agencia.proveedores (pantalla de Agencia)            → ver + gestionar
//   register_supplier (pantalla de Caja: podía crear)    → ver + gestionar
//   list/edit/delete_supplier (pantalla de Caja)         → ver
// Aplica a roles y a permisos directos de usuario. El Propietario además se sincroniza en cada
// deploy (roles:sync-propietario).

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ORIGEN = [
        'proveedores.ver' => ['agencia.proveedores', 'register_supplier', 'list_supplier', 'edit_supplier', 'delete_supplier'],
        'proveedores.gestionar' => ['agencia.proveedores', 'register_supplier'],
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::ORIGEN as $nuevo => $viejos) {
            $permiso = Permission::firstOrCreate(['guard_name' => 'api', 'name' => $nuevo]);
            $tieneViejo = fn ($q) => $q->whereIn('name', $viejos);

            Role::where('guard_name', 'api')->whereHas('permissions', $tieneViejo)->get()
                ->each(fn (Role $rol) => $rol->hasPermissionTo($permiso) || $rol->givePermissionTo($permiso));

            User::whereHas('permissions', $tieneViejo)->get()
                ->each(fn (User $u) => $u->hasDirectPermission($permiso) || $u->givePermissionTo($permiso));
        }
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')->whereIn('name', array_keys(self::ORIGEN))->delete();
    }
};
