<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_170100_add_agencia_permissions.php
//
// Sesión 11a del vertical Agencia de Viajes — permisos Spatie de la primera
// capa API real (mismo patrón dot-notation que 'cash.*',
// add_cash_session_permissions.php). A diferencia de esos, que viven en
// tenant/core/ porque Caja es un módulo genérico para todo tenant, estos
// viven en tenant/verticals/agencia-viajes/ a propósito: son permisos
// exclusivos de este giro, un tenant retail nunca debería verlos aparecer
// como opción en su pantalla de Roles y Permisos.
//
// Solo se crean las filas (firstOrCreate), sin asignarlas a ningún rol
// operativo por defecto — mismo criterio que el resto de permisos nuevos
// del proyecto. Super-Admin las tiene todas vía Gate::before
// (AppServiceProvider.php).

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'agencia.proveedores',
        'agencia.destinos',
        'agencia.temporadas',
        'agencia.guias',
        'agencia.configuracion',
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
