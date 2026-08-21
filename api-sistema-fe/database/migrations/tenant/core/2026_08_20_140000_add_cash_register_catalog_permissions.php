<?php

// CRUD de Cajas (cash_registers) — cierra el mismo gap que ya se había
// cerrado para Sucursales el 2026-08-17 (ver
// 2026_08_17_100000_add_branch_catalog_permissions.php, mismo patrón):
// cash_registers existía como tabla desde el módulo de Caja (Fase 1), pero
// CashRegisterController solo tenía index() — sin ninguna caja creada, la
// pantalla "Turno Activo" no tenía ningún botón que mostrar, solo el
// mensaje "No hay cajas disponibles para abrir".
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
        'register_cash_register', 'list_cash_register', 'edit_cash_register', 'delete_cash_register',
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
