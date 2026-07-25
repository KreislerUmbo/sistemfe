<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionsDemoSeeder extends Seeder
{
    /**
     * Todos los permisos usados por al menos un rol hoy en dev, más los 3 de
     * advances (register_advance/list_advance/refund_advance) — existen en dev
     * sin asignar a ningún rol todavía; se replican igual, fieles al estado
     * real, sin inventar una asignación nueva.
     */
    private const PERMISSIONS = [
        'dashboard',
        'register_role', 'list_role', 'edit_role', 'delete_role',
        'register_user', 'list_user', 'edit_user', 'delete_user',
        'register_categorie', 'edit_categorie', 'delete_categorie', 'list_categorie',
        'register_product', 'list_product', 'edit_product', 'delete_product',
        'register_client', 'list_client', 'edit_client', 'delete_client',
        'register_sale', 'list_sale', 'edit_sale', 'delete_sale',
        'register_guia_remision', 'list_guia_remision',
        'nota_electronica', 'list_nota_electronica',
        'register_advance', 'list_advance', 'refund_advance',
    ];

    /**
     * Roles + permisos, exactamente como existen hoy en dev (sv_facturacion) —
     * consultado directamente contra roles/role_has_permissions, no de memoria.
     * Super-Admin queda con 0 permisos explícitos a propósito: depende de
     * Gate::before() en AppServiceProvider (nombre ya corregido a 'Super-Admin').
     */
    private const ROLES = [
        'Super-Admin' => [],
        'Contador' => [
            'register_guia_remision', 'list_guia_remision',
            'nota_electronica', 'list_nota_electronica',
        ],
        'Jefe de Ventas' => [
            'register_product', 'list_product', 'edit_product', 'delete_product',
            'register_client', 'list_client',
            'register_sale', 'list_sale', 'edit_sale', 'delete_sale',
            'nota_electronica',
        ],
        'Jefe de Almacen' => [
            'register_product', 'list_product', 'edit_product', 'delete_product',
        ],
        'Cajero' => [
            'dashboard', 'register_sale', 'list_sale', 'edit_sale', 'delete_sale',
        ],
        'Vendedor' => [
            'register_product', 'list_product', 'edit_product',
            'register_sale', 'list_sale',
        ],
        'Cliente' => [
            'dashboard', 'list_categorie', 'list_product', 'list_sale',
        ],
    ];

    /**
     * Idempotente a propósito: debe poder correr más de una vez sobre el mismo
     * tenant (ej. re-provisioning, o tenants:seed corrido por error dos veces)
     * sin duplicar filas ni fallar. syncPermissions() en vez de givePermissionTo()
     * para que una corrida posterior también refleje si un permiso se sacó de
     * un rol, no solo si se agregó.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permission]);
        }

        foreach (self::ROLES as $roleName => $permissions) {
            $role = Role::firstOrCreate(['guard_name' => 'api', 'name' => $roleName]);
            $role->syncPermissions($permissions);
        }
    }
}
