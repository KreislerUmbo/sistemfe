<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Fase 0b (plan-modulo-menus-y-roles.md §9.1, Bucket A) — crea SOLO las filas
// de permisos nuevos que hacían falta para gatear las 38 rutas administrativas
// de Bucket A (roles/users/branches/cash-registers/payment-methods/suppliers/
// cash-concepts/series-comprobante ya tenían sus permisos register_X/edit_X/
// delete_X sembrados desde antes — esto solo cubre los que faltaban:
// delete_serie_comprobante, company, y los 3 de systems/system_categories/
// recursos c/u).
//
// Deliberadamente NO usa PermissionsDemoSeeder ni syncPermissions() acá:
// PermissionsDemoSeeder::run() hace syncPermissions() por rol contra un mapa
// fijo — correrlo contra un tenant real que ya divergió de ese mapa (ej.
// 'umbo', cuyo rol Contador tiene hoy permisos reales que el seeder no conoce:
// can_switch_branch, edit_sale, emitir_boleta/factura/nota_venta,
// list_serie_comprobante, register_serie_comprobante — confirmado por consulta
// directa 10-sep-2026) BORRARÍA esos permisos reales al resetear el rol al
// estado por defecto. Este comando solo hace Permission::firstOrCreate() — no
// toca role_has_permissions ni model_has_permissions de nadie, así que es
// seguro de correr contra cualquier tenant real sin riesgo de wipear una
// personalización ya hecha a mano.
class BackfillPermisosGateBucketA extends Command
{
    protected $signature = 'permisos:backfill-gate-bucket-a {tenant}';

    protected $description = 'Crea (idempotente) los permisos Spatie nuevos que necesita el gate de Bucket A (Fase 0b) en el tenant indicado. No asigna el permiso a ningún rol.';

    private const PERMISOS_NUEVOS = [
        'delete_serie_comprobante',
        'company',
        'register_system', 'edit_system', 'delete_system',
        'register_categorie_system', 'edit_categorie_system', 'delete_categorie_system',
        'register_recurso', 'edit_recurso', 'delete_recurso',
    ];

    // Hallazgo real (10-sep-2026, revisión pre-merge de Fase 0b, consulta directa contra
    // agencia-demo): a diferencia de sandbox/umbo/negocio2 (donde el único que administra
    // roles/usuarios/sucursales/proveedores es Super-Admin, que bypasea el gate),
    // agencia-demo SÍ tiene un rol real en uso — 'Admin-General' (usuario real
    // admin@gmail.com) — con 14 de los permisos de Bucket A asignados, pero le faltan estos
    // 6. Son permisos que YA EXISTÍAN antes de Fase 0b (no están en PERMISOS_NUEVOS) — sin
    // este otorgamiento, gatear branches/suppliers le rompería a admin@gmail.com una
    // capacidad que hoy usa sin gate (crear/editar/eliminar sucursales y proveedores).
    // Exclusivo de agencia-demo: NO se otorga en ningún otro tenant.
    private const PERMISOS_FALTANTES_ADMIN_GENERAL_AGENCIA_DEMO = [
        'register_branch', 'edit_branch', 'delete_branch',
        'register_supplier', 'edit_supplier', 'delete_supplier',
    ];

    public function handle(): int
    {
        $tenantId = $this->argument('tenant');
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            $this->error("No existe el tenant '{$tenantId}'.");

            return self::FAILURE;
        }

        $tenant->run(function () use ($tenantId) {
            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            foreach (self::PERMISOS_NUEVOS as $nombre) {
                $permiso = Permission::firstOrCreate(['guard_name' => 'api', 'name' => $nombre]);
                $this->info(($permiso->wasRecentlyCreated ? 'Creado' : 'Ya existía') . ": {$nombre}");
            }

            if ($tenantId === 'agencia-demo') {
                $this->otorgarPermisosFaltantesAdminGeneral();
            }
        });

        return self::SUCCESS;
    }

    private function otorgarPermisosFaltantesAdminGeneral(): void
    {
        $role = Role::where('guard_name', 'api')->where('name', 'Admin-General')->first();

        if (! $role) {
            $this->warn("Rol 'Admin-General' no encontrado en agencia-demo — omitiendo otorgamiento (¿cambió de nombre? revisar a mano).");

            return;
        }

        foreach (self::PERMISOS_FALTANTES_ADMIN_GENERAL_AGENCIA_DEMO as $nombre) {
            if ($role->hasPermissionTo($nombre)) {
                $this->info("Admin-General ya tenía: {$nombre}");

                continue;
            }

            $role->givePermissionTo($nombre);
            $this->info("Otorgado a Admin-General: {$nombre}");
        }
    }
}
