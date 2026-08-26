<?php

// Permisos del módulo nuevo "Cotizaciones Comerciales" — sin asignar a
// ningún rol operativo por defecto (mismo criterio que el resto de
// catálogos/módulos nuevos del proyecto). Super-Admin las tiene todas vía
// Gate::before (AppServiceProvider.php).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'list_commercial_quote', 'register_commercial_quote',
        'edit_commercial_quote', 'convert_commercial_quote',
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
