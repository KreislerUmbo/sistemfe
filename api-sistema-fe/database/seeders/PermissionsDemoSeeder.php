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

        // Fase 0b (plan-modulo-menus-y-roles.md §9.1, Bucket A) — permisos
        // nuevos que hacían falta para gatear rutas administrativas que hoy
        // solo tenían auth:api. No se agregan a ningún rol de ROLES abajo a
        // propósito: la investigación de Fase 0b confirmó que ningún rol de
        // negocio real (en sandbox/umbo/negocio2) los necesita hoy — solo
        // Super-Admin los usa, y Super-Admin bypasea el gate vía
        // Gate::before() en AppServiceProvider.
        'delete_serie_comprobante',
        'company',
        'register_system', 'edit_system', 'delete_system',
        'register_categorie_system', 'edit_categorie_system', 'delete_categorie_system',
        'register_recurso', 'edit_recurso', 'delete_recurso',

        // 12-sep-2026 (plan-modulo-menus-y-roles.md) — hallazgo real: este
        // seeder quedó desactualizado frente a módulos construidos después de
        // escribirlo (Caja, Series de Comprobantes, Cotizaciones Comerciales,
        // Amortizaciones/Créditos) — confirmado comparando contra los 71
        // permisos reales de `umbo` (39 de diferencia). Un tenant nuevo
        // provisionado con este seeder arrancaba sin poder abrir caja, emitir
        // factura, ni usar series de comprobante, pese a que esos módulos ya
        // están disponibles para cualquier giro. Se agregan acá, fieles al
        // estado real de `umbo` (verificado con tinker, no de memoria) — ver
        // asignación por rol más abajo.
        'cash.open_session', 'cash.view_all', 'cash.close_others_session', 'cash.approve_expenses',
        'register_branch', 'list_branch', 'edit_branch', 'delete_branch',
        'register_cash_register', 'list_cash_register', 'edit_cash_register', 'delete_cash_register',
        'register_payment_method', 'list_payment_method', 'edit_payment_method', 'delete_payment_method',
        'register_supplier', 'list_supplier', 'edit_supplier', 'delete_supplier',
        'register_cash_concept', 'list_cash_concept', 'edit_cash_concept', 'delete_cash_concept',
        'register_serie_comprobante', 'list_serie_comprobante', 'edit_serie_comprobante',
        'can_switch_branch', 'emitir_factura', 'emitir_boleta', 'emitir_nota_venta',
        'register_commercial_quote', 'list_commercial_quote', 'edit_commercial_quote', 'convert_commercial_quote',
        'anular-cuota-credito', 'anular-pago-credito', 'liquidar-devolucion-credito', 'reemplazar-comprobante-credito',
    ];

    /**
     * Roles + permisos, exactamente como existen hoy en dev (sv_facturacion) —
     * consultado directamente contra roles/role_has_permissions, no de memoria.
     * Super-Admin queda con 0 permisos explícitos a propósito: depende de
     * Gate::before() en AppServiceProvider (nombre ya corregido a 'Super-Admin').
     *
     * 12-sep-2026: Contador/Cajero/Vendedor actualizados con lo que
     * `umbo` (tenant real) ya tenía asignado vía backfills sueltos de sesiones
     * posteriores (Caja, Series de Comprobantes) — verificado contra
     * `role->permissions` real de `umbo`, no inventado. Jefe de Ventas/Jefe de
     * Almacen/Cliente/Super-Admin sin cambios: ningún permiso nuevo de la
     * lista de arriba está asignado a esos roles en `umbo` tampoco — no se
     * inventa una asignación que no existe hoy. `list_branch`/
     * `list_cash_register`/`list_payment_method`/`list_supplier`/
     * `list_cash_concept`/`cash.view_all` (grupo "Configuraciones" +
     * "Historial y Reportes" del menú) quedan sin asignar a ningún rol de
     * negocio a propósito — en `umbo` tampoco los tiene nadie fuera de
     * Super-Admin, esas pantallas son de configuración/administración.
     */
    private const ROLES = [
        'Super-Admin' => [],
        'Contador' => [
            'register_guia_remision', 'list_guia_remision',
            'nota_electronica', 'list_nota_electronica',
            'can_switch_branch', 'emitir_factura', 'emitir_boleta', 'emitir_nota_venta',
            'register_serie_comprobante', 'list_serie_comprobante', 'edit_serie_comprobante',
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
            'cash.open_session',
        ],
        'Vendedor' => [
            'register_product', 'list_product', 'edit_product',
            'register_sale', 'list_sale',
            'cash.open_session',
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
