<?php

namespace Database\Seeders;

use App\Contracts\RolesCatalogProvider;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Módulo Créditos (plan 1.13) — roles del giro 'creditos', en capas encima de
 * PermissionsDemoSeeder (mismo criterio que AgenciaViajesRolesSeeder: nunca lo
 * reemplaza). Corre en TenantProvisioningService::provision() cuando giro='creditos';
 * el admin del tenant puede ajustar permisos después desde Roles.
 *
 * - "Solo su cartera" del cobrador no es un permiso: se aplica en el backend (scope de
 *   consultas + validación del cobro, 1.13), por eso el Cobrador no recibe list_client.
 * - Anular el propio pago con la misma sesión de caja abierta tampoco es un permiso
 *   (1.9); creditos.anular_pago es para cualquier pago o caja cerrada.
 */
class CreditosRolesSeeder extends Seeder implements RolesCatalogProvider
{
    private const PERMISOS_CREDITOS = [
        'creditos.ver', 'creditos.crear', 'creditos.cobrar', 'creditos.anular_pago',
        'creditos.condonar_mora', 'creditos.corregir', 'creditos.configurar',
        'creditos.prendas.gestionar', 'creditos.prendas.vender', 'creditos.cartera.asignar',
        'creditos.autorizar_excepcion', 'creditos.reprogramar', 'creditos.castigar',
        'creditos.migrar', 'creditos.pago_fecha_anterior',
    ];

    private const PERMISOS_ADMINISTRACION = [
        'register_client', 'list_client', 'edit_client', 'delete_client',
        'cash.open_session', 'cash.view_all', 'cash.close_others_session', 'cash.approve_expenses',
        'register_role', 'edit_role', 'delete_role', 'list_role',
        'register_user', 'edit_user', 'delete_user', 'list_user',
        'company',
        'register_branch', 'edit_branch', 'delete_branch', 'list_branch',
        'register_cash_register', 'edit_cash_register', 'delete_cash_register', 'list_cash_register',
        'register_payment_method', 'edit_payment_method', 'delete_payment_method', 'list_payment_method',
        'register_cash_concept', 'edit_cash_concept', 'delete_cash_concept', 'list_cash_concept',
    ];

    private const ROLES = [
        'Administrador de créditos' => [...self::PERMISOS_CREDITOS, ...self::PERMISOS_ADMINISTRACION],
        'Cajero de créditos' => [
            'creditos.ver', 'creditos.crear', 'creditos.cobrar', 'creditos.prendas.gestionar',
            'register_client', 'list_client', 'edit_client',
            'cash.open_session',
        ],
        'Cobrador' => [
            'creditos.ver', 'creditos.cobrar',
            'cash.open_session',
        ],
    ];

    public function permisos(): array
    {
        return array_values(array_unique([...self::PERMISOS_CREDITOS, ...self::PERMISOS_ADMINISTRACION]));
    }

    public function roles(): array
    {
        return self::ROLES;
    }

    public function run(): void
    {
        foreach ($this->permisos() as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
        }

        foreach (self::ROLES as $roleName => $permisos) {
            $role = Role::firstOrCreate(['guard_name' => 'api', 'name' => $roleName]);

            $faltantes = array_values(array_diff($permisos, $role->permissions()->pluck('name')->all()));
            if ($faltantes !== []) {
                $role->givePermissionTo($faltantes);
            }
        }
    }
}
