<?php

// CRUD de Sucursales — cierra el gap real: branches existía como tabla desde
// el módulo de Caja (Fase 1) pero nunca tuvo permisos propios ni CRUD
// (BranchController solo tenía index(), sin store/update/destroy) — mismo
// estilo snake_case que add_cash_catalog_permissions.php (catálogo simple,
// no acción puntual de supervisor).
//
// Solo se crean las filas (firstOrCreate), sin asignarlas a ningún rol
// operativo por defecto — mismo criterio que el resto de catálogos de Caja.
// Super-Admin las tiene todas vía Gate::before (AppServiceProvider.php).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'register_branch', 'list_branch', 'edit_branch', 'delete_branch',
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $name]);
        }
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->whereIn('name', self::PERMISSIONS)
            ->delete();
    }
};
