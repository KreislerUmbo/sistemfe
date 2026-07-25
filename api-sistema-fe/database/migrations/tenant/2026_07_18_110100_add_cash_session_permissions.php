<?php

// Módulo Caja — Fase 2 (plan-modulo-caja.md §9). Nombres con punto
// ('cash.open_session', no 'open_cash_session' ni kebab-case) porque así
// los fija el plan mismo en la sección de navegación — no es una convención
// nueva que yo esté introduciendo, es la que el plan ya usa textualmente.
//
// Solo se crean las filas (firstOrCreate), sin asignarlas a ningún rol
// operativo por defecto — mismo criterio que el resto de permisos nuevos de
// este proyecto (Adelantos, Amortizaciones, catálogos de Fase 0 de Caja).
// Super-Admin las tiene todas vía Gate::before (AppServiceProvider.php).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'cash.open_session',
        'cash.view_all',
        'cash.close_others_session',
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
