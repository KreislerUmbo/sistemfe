<?php

// T-109 — Permiso para cambiar el precio de una línea en la venta. Solo se crea la fila: ningún
// rol operativo lo recibe solo (mismo criterio que emitir_*); el Propietario lo recibe con
// `roles:sync-propietario` en el deploy. La validación real vive en SaleController
// (PrecioVentaValidator), no solo en la pantalla.

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = ['cambiar_precio_venta'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $name]);
        }
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')->whereIn('name', self::PERMISSIONS)->delete();
    }
};
