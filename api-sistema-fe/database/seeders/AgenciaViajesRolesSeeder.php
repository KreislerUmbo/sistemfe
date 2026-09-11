<?php

namespace Database\Seeders;

use App\Contracts\RolesCatalogProvider;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Fase 1b (plan-modulo-menus-y-roles.md §3.2/§3.3/§7) — catálogo real de
 * roles/permisos del giro `agencia_viajes`. Corre DESPUÉS de
 * PermissionsDemoSeeder dentro de TenantProvisioningService::provision()
 * (nunca lo reemplaza — agencia_viajes también usa las tablas 'core'
 * retail que ese seeder cubre: categorías/productos/clientes/ventas,
 * compartidas por todos los giros).
 *
 * Implementa RolesCatalogProvider para poder reusarse con
 * `roles:sync-permisos-nuevos` (Fase 1a) el día que este catálogo gane un
 * permiso nuevo y haga falta empujarlo a tenants agencia_viajes YA
 * provisionados.
 *
 * Nunca usa syncPermissions() — solo agrega lo que falta (mismo criterio
 * que permisos:backfill-gate-bucket-a y roles:sync-permisos-nuevos). Esto
 * importa en particular para el rol 'Contador': PermissionsDemoSeeder ya lo
 * crea (con permisos retail-flavored: register_guia_remision/
 * nota_electronica) — acá se AGREGAN los permisos de agencia_viajes al
 * mismo rol, nunca se reemplaza ni se duplica.
 *
 * Mapeo ruta→permiso completo (por qué 'ver_todas' hace de OR con 'ver' en
 * las rutas de lectura, por qué 'eliminar' se fusionó con 'editar', qué
 * rutas quedaron en cada bucket) documentado en
 * docs/planning/agencia-de-viajes/fase1b-roles-permisos.md — no repetido
 * acá para no desincronizarse de la fuente real (routes/api.php).
 *
 * `agencia.cotizaciones`/`agencia.reservas` (los permisos planos
 * originales, Sesión 11b/11c) NO se retiran: routes/api.php ya no los usa
 * para gatear acciones (reemplazados por los granulares de abajo), pero
 * `menu_items` (Fase 1a, MenuItemsSeeder) sigue usándolos como el
 * permiso_requerido de los ítems "Cotizador"/"Reservas"/"Reporte
 * Operativo"/"Salidas Operativas"/"Venta Directa" del menú — son ahora
 * puramente "¿ve esta sección del menú?", separado de "¿qué puede hacer
 * una vez adentro?" (los granulares). Cada rol de acá abajo que necesita
 * ver esas secciones recibe también el plano correspondiente.
 */
class AgenciaViajesRolesSeeder extends Seeder implements RolesCatalogProvider
{
    private const PERMISOS = [
        // Nuevos — granulares, Fase 1b.
        'cotizaciones.ver', 'cotizaciones.ver_todas', 'cotizaciones.crear', 'cotizaciones.editar',
        'reservas.ver', 'reservas.ver_todas', 'reservas.crear', 'reservas.editar',
        'roles.administrar',

        // Reusados — ya existían (agencia.* Sesión 11a-12e, o Bucket A/B
        // Fase 0b/0c) — firstOrCreate() es idempotente, listados igual acá
        // para que permisos() (RolesCatalogProvider) sea la fuente completa.
        'agencia.cotizaciones', 'agencia.reservas', 'agencia.proveedores', 'agencia.destinos',
        'agencia.temporadas', 'agencia.guias', 'agencia.paquetes', 'agencia.configuracion',
        'nota_electronica', 'list_nota_electronica',
        'cash.open_session', 'cash.view_all',
        'list_sale',
        'register_role', 'edit_role', 'delete_role', 'list_role',
        'register_user', 'edit_user', 'delete_user', 'list_user',
        'company',
        'register_branch', 'edit_branch', 'delete_branch', 'list_branch',
        'register_cash_register', 'edit_cash_register', 'delete_cash_register', 'list_cash_register',
        'register_payment_method', 'edit_payment_method', 'delete_payment_method', 'list_payment_method',
        'register_supplier', 'edit_supplier', 'delete_supplier', 'list_supplier',
        'register_cash_concept', 'edit_cash_concept', 'delete_cash_concept', 'list_cash_concept',
        'register_serie_comprobante', 'edit_serie_comprobante', 'delete_serie_comprobante', 'list_serie_comprobante',
    ];

    // 'Super-Admin' no aparece — ya existe (Gate::before, AppServiceProvider),
    // no se le asigna nada acá, mismo criterio que PermissionsDemoSeeder.
    private const ROLES = [
        'Administrador de agencia' => [
            'cotizaciones.ver', 'cotizaciones.ver_todas', 'cotizaciones.crear', 'cotizaciones.editar', 'agencia.cotizaciones',
            'reservas.ver', 'reservas.ver_todas', 'reservas.crear', 'reservas.editar', 'agencia.reservas',
            'agencia.proveedores', 'agencia.destinos', 'agencia.temporadas', 'agencia.guias', 'agencia.paquetes', 'agencia.configuracion',
            'nota_electronica', 'list_nota_electronica',
            'cash.open_session', 'cash.view_all',
            'list_sale',
            'roles.administrar',
            'register_role', 'edit_role', 'delete_role', 'list_role',
            'register_user', 'edit_user', 'delete_user', 'list_user',
            'company',
            'register_branch', 'edit_branch', 'delete_branch', 'list_branch',
            'register_cash_register', 'edit_cash_register', 'delete_cash_register', 'list_cash_register',
            'register_payment_method', 'edit_payment_method', 'delete_payment_method', 'list_payment_method',
            'register_supplier', 'edit_supplier', 'delete_supplier', 'list_supplier',
            'register_cash_concept', 'edit_cash_concept', 'delete_cash_concept', 'list_cash_concept',
            'register_serie_comprobante', 'edit_serie_comprobante', 'delete_serie_comprobante', 'list_serie_comprobante',
        ],
        // Igual que Administrador en el flujo operativo/comercial — SIN
        // roles.administrar ni "Configuración del tenant" (§3.2, confirmado
        // 10-sep-2026: esa es la línea que lo distingue de Administrador).
        'Supervisor' => [
            'cotizaciones.ver', 'cotizaciones.ver_todas', 'cotizaciones.crear', 'cotizaciones.editar', 'agencia.cotizaciones',
            'reservas.ver', 'reservas.ver_todas', 'reservas.crear', 'reservas.editar', 'agencia.reservas',
            'agencia.proveedores', 'agencia.destinos', 'agencia.temporadas', 'agencia.guias', 'agencia.paquetes',
            'nota_electronica', 'list_nota_electronica',
            'cash.open_session', 'cash.view_all',
            'list_sale',
        ],
        // Alcance propio (§3.3: sin ver_todas, EscopablePorVendedor lo
        // limita a sus propias filas). SIN reservas.editar a propósito —
        // §3.2 solo lista "reservas.ver/crear" para este rol: una vez que
        // una cotización se aceptó y es una Reserva real, cancelarla/
        // reprogramarla/facturarla queda para Administrador/Supervisor.
        'Vendedor de agencia' => [
            'cotizaciones.ver', 'cotizaciones.crear', 'cotizaciones.editar', 'agencia.cotizaciones',
            'reservas.ver', 'reservas.crear', 'agencia.reservas',
            'cash.open_session',
        ],
        // Solo lectura de Cotizaciones/Reservas (ver_todas, sin crear/
        // editar) + NC/ND + Caja consolidada + Ventas — "opera lo fiscal/
        // contable, necesita visión completa para conciliar" (§3.2).
        'Contador' => [
            'cotizaciones.ver_todas', 'agencia.cotizaciones',
            'reservas.ver_todas', 'agencia.reservas',
            'nota_electronica', 'list_nota_electronica',
            'cash.view_all',
            'list_sale',
        ],
    ];

    public function permisos(): array
    {
        return self::PERMISOS;
    }

    public function roles(): array
    {
        return self::ROLES;
    }

    public function run(): void
    {
        foreach (self::PERMISOS as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
        }

        foreach (self::ROLES as $roleName => $permisos) {
            $role = Role::firstOrCreate(['guard_name' => 'api', 'name' => $roleName]);

            $tiene = $role->permissions()->pluck('name')->all();
            $faltantes = array_values(array_diff($permisos, $tiene));

            if ($faltantes !== []) {
                $role->givePermissionTo($faltantes);
            }
        }
    }
}
