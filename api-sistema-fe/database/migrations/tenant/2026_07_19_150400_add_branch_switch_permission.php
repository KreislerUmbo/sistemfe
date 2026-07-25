<?php
// Módulo de series de comprobantes — Paso 3.5. Permiso independiente del rol
// (mismo criterio que cash.close_others_session): un cajero de confianza que
// cubre turno en más de una sucursal lo recibe directo, sin necesidad de
// subirlo a un rol con más alcance. Sin este permiso, register.vue/edit.vue
// usan directo users.branch_id sin mostrar ningún selector.
//
// Solo se crea la fila (firstOrCreate), sin asignarla a ningún rol operativo
// por defecto — mismo criterio que add_advance_permissions.php y
// add_credit_annulment_permissions.php. Super-Admin la tiene vía Gate::before.

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'can_switch_branch',
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
