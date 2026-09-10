<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
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

    public function handle(): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if (! $tenant) {
            $this->error("No existe el tenant '{$this->argument('tenant')}'.");

            return self::FAILURE;
        }

        $tenant->run(function () {
            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            foreach (self::PERMISOS_NUEVOS as $nombre) {
                $permiso = Permission::firstOrCreate(['guard_name' => 'api', 'name' => $nombre]);
                $this->info(($permiso->wasRecentlyCreated ? 'Creado' : 'Ya existía') . ": {$nombre}");
            }
        });

        return self::SUCCESS;
    }
}
