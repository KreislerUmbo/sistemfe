<?php

// T-136 — permisos nuevos: cuentas_bancarias.gestionar (las cuentas salen de Agencia) y
// compras.pagar.
//
// cuentas_bancarias.gestionar: quien ya gestionaba las cuentas (agencia.configuracion, por rol o
// directo) lo sigue haciendo. compras.pagar no se reparte: como el resto de compras.*, ningún rol
// operativo lo recibe. El Propietario los recibe en el deploy (roles:sync-propietario).

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'compras.pagar']);
        $cuentas = Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'cuentas_bancarias.gestionar']);

        $tenia = fn ($q) => $q->where('name', 'agencia.configuracion');

        Role::where('guard_name', 'api')->whereHas('permissions', $tenia)->get()
            ->each(fn (Role $rol) => $rol->hasPermissionTo($cuentas) || $rol->givePermissionTo($cuentas));

        User::whereHas('permissions', $tenia)->get()
            ->each(fn (User $u) => $u->hasDirectPermission($cuentas) || $u->givePermissionTo($cuentas));
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->whereIn('name', ['compras.pagar', 'cuentas_bancarias.gestionar'])->delete();
    }
};
