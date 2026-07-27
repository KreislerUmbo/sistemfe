<?php

// Módulo Caja — Fase 4 (plan-modulo-caja.md §6). Mismo criterio que
// 2026_07_18_110100_add_cash_session_permissions.php (Fase 2): nombre con
// punto, firstOrCreate, sin asignar a ningún rol operativo por defecto.
// Super-Admin lo tiene vía Gate::before (AppServiceProvider.php).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'cash.approve_expenses']);
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->where('name', 'cash.approve_expenses')
            ->delete();
    }
};
