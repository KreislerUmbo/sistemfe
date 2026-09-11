<?php

namespace App\Console\Commands;

use Database\Seeders\AgenciaViajesRolesSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Fase 1b (plan-modulo-menus-y-roles.md §3.2/§7) — dos cosas, idempotentes,
 * en un solo comando:
 *
 * 1. Corre AgenciaViajesRolesSeeder contra el tenant (crea los 4 roles
 *    nuevos + agrega sus permisos, mismo mecanismo que usaría un tenant
 *    NUEVO vía provision() — este comando es el equivalente para un tenant
 *    YA existente).
 * 2. Backfill de regresión: cualquier ROL que ya tuviera el permiso plano
 *    'agencia.cotizaciones'/'agencia.reservas' (Sesión 11b/11c) recibe
 *    también el set completo de permisos granulares equivalentes
 *    (cotizaciones.ver/ver_todas/crear/editar, ídem reservas) — sin esto,
 *    cualquier rol real que hoy accede a Cotizaciones/Reservas vía el
 *    permiso plano quedaría bloqueado apenas se mergee el split de rutas
 *    (routes/api.php ya no chequea el plano, ver
 *    docs/planning/agencia-de-viajes/fase1b-roles-permisos.md). Da el set
 *    COMPLETO (ver_todas incluido) a propósito: preservar el acceso que ya
 *    tenían (sin restricción de alcance) es la única opción segura sin
 *    preguntar rol por rol qué scope les corresponde — achicar el alcance
 *    de un rol real es una decisión de negocio, no algo que este comando
 *    deba asumir.
 *
 * Nunca usa syncPermissions() — mismo criterio que
 * permisos:backfill-gate-bucket-a/roles:sync-permisos-nuevos.
 *
 * BUG REAL encontrado y corregido antes de correr esto contra ningún tenant
 * real (verificación en vivo contra sandbox, no por ningún test): el paso 2
 * escaneaba CUALQUIER rol con el permiso plano — incluidos los 4 que el
 * propio AgenciaViajesRolesSeeder acaba de crear en el paso 1
 * ('Contador'/'Vendedor de agencia' también reciben agencia.cotizaciones/
 * agencia.reservas, para visibilidad de menú, ver el seeder) — y les daba
 * el set COMPLETO granular (incluido ver_todas/crear/editar), pisando la
 * restricción deliberada de alcance de §3.2 (Contador sin crear, Vendedor
 * sin ver_todas/editar). El paso 2 ahora EXCLUYE explícitamente los roles
 * que AgenciaViajesRolesSeeder::roles() ya administra — solo backfillea
 * roles AJENOS al catálogo (ej. 'Admin-General', o cualquier rol custom que
 * un tenant real haya armado a mano).
 */
class BackfillFase1bAgenciaViajes extends Command
{
    protected $signature = 'permisos:backfill-fase1b-agencia-viajes {tenant}';

    protected $description = 'Siembra el catálogo de roles de Fase 1b y backfillea a granular cualquier rol AJENO al catálogo que ya tuviera agencia.cotizaciones/agencia.reservas plano.';

    private const GRANULARES_COTIZACIONES = ['cotizaciones.ver', 'cotizaciones.ver_todas', 'cotizaciones.crear', 'cotizaciones.editar'];
    private const GRANULARES_RESERVAS = ['reservas.ver', 'reservas.ver_todas', 'reservas.crear', 'reservas.editar'];

    public function handle(): int
    {
        $tenantId = $this->argument('tenant');
        $tenant = \App\Models\Tenant::find($tenantId);

        if (! $tenant) {
            $this->error("Tenant '{$tenantId}' no encontrado.");

            return self::FAILURE;
        }

        $tenant->run(function () {
            (new AgenciaViajesRolesSeeder())->run();

            foreach (self::GRANULARES_COTIZACIONES as $permiso) {
                Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            }
            foreach (self::GRANULARES_RESERVAS as $permiso) {
                Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            }

            $this->backfillearRol('agencia.cotizaciones', self::GRANULARES_COTIZACIONES);
            $this->backfillearRol('agencia.reservas', self::GRANULARES_RESERVAS);
        });

        return self::SUCCESS;
    }

    private function backfillearRol(string $permisoPlano, array $granulares): void
    {
        $rolesDelCatalogo = array_keys((new AgenciaViajesRolesSeeder())->roles());

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permisoPlano))
            ->whereNotIn('name', $rolesDelCatalogo)
            ->get();

        foreach ($roles as $role) {
            $tiene = $role->permissions()->pluck('name')->all();
            $faltantes = array_values(array_diff($granulares, $tiene));

            if ($faltantes === []) {
                continue;
            }

            $role->givePermissionTo($faltantes);
            $this->line("  {$role->name}: +" . implode(',', $faltantes) . " (tenía {$permisoPlano})");
        }
    }
}
