<?php

// Permisos del módulo Adelantos, mismo criterio que PermissionsDemoSeeder.php
// (que ya corrió y no debe volver a ejecutarse — usar firstOrCreate acá para
// no chocar con datos existentes). Super-Admin los tiene todos vía
// Gate::before, sin depender de estas filas — esto es para poder asignarlos
// a otros roles desde la UI de Roles y Permisos.

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['register_advance', 'list_advance', 'refund_advance'] as $name) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $name]);
        }
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->whereIn('name', ['register_advance', 'list_advance', 'refund_advance'])
            ->delete();
    }
};
