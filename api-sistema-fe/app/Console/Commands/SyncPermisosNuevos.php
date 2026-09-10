<?php

namespace App\Console\Commands;

use App\Contracts\RolesCatalogProvider;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Fase 1a (plan-modulo-menus-y-roles.md §9.4) — análogo a
 * tenants:migrate-verticales (mismo patrón: iterar tenants ya provisionados,
 * aplicar una acción idempotente). Agrega a los ROLES BASE de tenants YA
 * existentes cualquier permiso nuevo de un catálogo que todavía no tengan.
 *
 * Nunca usa syncPermissions() — eso pisaría personalizaciones manuales ya
 * hechas a mano sobre un rol (mismo riesgo real que motivó a
 * permisos:backfill-gate-bucket-a a evitarlo, ver
 * docs/planning/claude/gate-bucket-a-fase0b.md). Solo agrega lo que falta
 * (givePermissionTo() con la diferencia), nunca quita nada, y nunca crea un
 * rol que el tenant no tenga sembrado ya — crear roles nuevos es
 * responsabilidad del seeder de giro (§3.2), no de este comando de
 * mantenimiento.
 *
 * Sin uso real todavía en Fase 1a: el catálogo real de agencia_viajes lo
 * siembra Fase 1b (AgenciaViajesRolesSeeder, o el nombre que decida esa
 * sesión, implementando RolesCatalogProvider). Construido y testeado acá con
 * un catálogo de prueba — ver tests/Feature/SyncPermisosNuevosCommandTest.php.
 */
class SyncPermisosNuevos extends Command
{
    protected $signature = 'roles:sync-permisos-nuevos
        {catalogo : FQCN de una clase que implementa App\\Contracts\\RolesCatalogProvider}
        {--tenant=* : id de tenant a procesar (default: todos los provisionados)}';

    protected $description = 'Agrega a los roles base de tenants ya provisionados los permisos nuevos de un catálogo, sin pisar personalizaciones manuales.';

    public function handle(): int
    {
        $catalogoClase = $this->argument('catalogo');

        if (! class_exists($catalogoClase) || ! is_subclass_of($catalogoClase, RolesCatalogProvider::class)) {
            $this->error("{$catalogoClase} no existe o no implementa " . RolesCatalogProvider::class . '.');

            return self::FAILURE;
        }

        /** @var RolesCatalogProvider $catalogo */
        $catalogo = app($catalogoClase);
        $permisos = $catalogo->permisos();
        $roles = $catalogo->roles();

        $tenantIds = $this->option('tenant');
        $tenants = $tenantIds ? Tenant::whereIn('id', $tenantIds)->get() : Tenant::all();

        $resumen = [];

        foreach ($tenants as $tenant) {
            $resumen[] = $this->procesarTenant($tenant, $permisos, $roles);
        }

        $this->newLine();
        $this->table(['Tenant', 'Roles tocados', 'Permisos agregados'], $resumen);

        return self::SUCCESS;
    }

    private function procesarTenant(Tenant $tenant, array $permisos, array $roles): array
    {
        return $tenant->run(function () use ($tenant, $permisos, $roles) {
            foreach ($permisos as $permiso) {
                Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            }

            $rolesTocados = 0;
            $permisosAgregados = 0;

            foreach ($roles as $roleName => $permisosDelRol) {
                $role = Role::where('guard_name', 'api')->where('name', $roleName)->first();

                if (! $role) {
                    // El tenant no tiene este rol sembrado — no lo crea este comando.
                    continue;
                }

                $tiene = $role->permissions()->pluck('name')->all();
                $faltantes = array_values(array_diff($permisosDelRol, $tiene));

                if ($faltantes === []) {
                    continue;
                }

                $role->givePermissionTo($faltantes);
                $this->line("  [{$tenant->id}] {$roleName}: +" . implode(',', $faltantes));

                $rolesTocados++;
                $permisosAgregados += count($faltantes);
            }

            return [$tenant->id, $rolesTocados, $permisosAgregados];
        });
    }
}
