<?php

// T-135 — permisos de Compras. Sin backfill: son nuevos. El Propietario los recibe en el deploy
// (roles:sync-propietario).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISOS = ['compras.ver', 'compras.registrar', 'compras.anular', 'compras.cerrar_periodo'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISOS as $nombre) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $nombre]);
        }
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')->whereIn('name', self::PERMISOS)->delete();
    }
};
