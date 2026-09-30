<?php

// Módulo Créditos (plan §0, 1.13) — permisos del giro 'creditos'. Se crean en
// todos los tenants (mismo criterio que el resto de módulos nuevos: sin asignar
// a ningún rol por defecto); los roles del giro los asigna CreditosRolesSeeder
// al provisionar. Super-Admin las tiene todas vía Gate::before.

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'creditos.ver', 'creditos.crear', 'creditos.cobrar', 'creditos.anular_pago',
        'creditos.condonar_mora', 'creditos.corregir', 'creditos.configurar',
        'creditos.prendas.gestionar', 'creditos.prendas.vender', 'creditos.cartera.asignar',
        'creditos.autorizar_excepcion', 'creditos.reprogramar', 'creditos.castigar',
        'creditos.migrar', 'creditos.pago_fecha_anterior',
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
