<?php

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Permisos de los 3 catálogos
// nuevos, mismo estilo snake_case que register_categorie/list_categorie/
// edit_categorie/delete_categorie (PermissionsDemoSeeder) — no el estilo
// kebab-case de permission:... usado en Amortizaciones, porque esos son
// acciones puntuales de supervisor con enforcement de ruta; estos son CRUD
// de catálogo simple, igual que Categorías/Productos/Clientes.
//
// Solo se crean las filas (firstOrCreate), sin asignarlas a ningún rol
// operativo por defecto — mismo criterio que add_advance_permissions.php y
// add_credit_annulment_permissions.php. Super-Admin las tiene todas vía
// Gate::before (AppServiceProvider.php), queda para decidirse después desde
// la UI de Roles y Permisos quién más las tiene.

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'register_payment_method', 'list_payment_method', 'edit_payment_method', 'delete_payment_method',
        'register_supplier', 'list_supplier', 'edit_supplier', 'delete_supplier',
        'register_cash_concept', 'list_cash_concept', 'edit_cash_concept', 'delete_cash_concept',
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
