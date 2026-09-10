<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\CatalogoDePruebaRoles;
use Tests\TestCase;

// Fase 1a (plan-modulo-menus-y-roles.md §9.4) — roles:sync-permisos-nuevos
// contra un tenant físico real y descartable, mismo patrón que
// MigrateVerticalesPendientesTest (Tenant::create() dispara CreateDatabase +
// MigrateDatabase síncrono — es la única forma de probar de verdad que
// givePermissionTo() aplicó sobre la base real del tenant).
class SyncPermisosNuevosCommandTest extends TestCase
{
    /** @var Tenant[] */
    private array $tenantsCreados = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => env('DB_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => env('DB_PORT', '5432'),
            'database.connections.pgsql.database' => 'sistemafe_test_migrations',
            'database.connections.pgsql.username' => env('DB_USERNAME', 'root'),
            'database.connections.pgsql.password' => env('DB_PASSWORD', ''),
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');

        $this->asegurarTablasCentrales();
    }

    protected function tearDown(): void
    {
        foreach ($this->tenantsCreados as $tenant) {
            $tenant = $tenant->fresh();

            if (! $tenant) {
                continue;
            }

            if ($tenant->database()->manager()->databaseExists($tenant->database()->getName())) {
                $tenant->database()->manager()->deleteDatabase($tenant);
            }

            $tenant->delete();
        }

        parent::tearDown();
    }

    private function asegurarTablasCentrales(): void
    {
        if (Schema::connection('central')->hasTable('tenants')) {
            return;
        }

        Artisan::call('migrate', [
            '--path' => [
                'database/migrations/2019_09_15_000010_create_tenants_table.php',
                'database/migrations/2019_09_15_000020_create_domains_table.php',
                'database/migrations/2026_07_13_120000_add_status_to_tenants_table.php',
                'database/migrations/2026_07_27_090000_add_giro_tipo_sunat_modo_to_tenants_table.php',
            ],
            '--force' => true,
        ]);
    }

    private function crearTenantDescartable(): Tenant
    {
        $id = 'test-sync-' . Str::lower(Str::random(8));

        $tenant = Tenant::create([
            'id' => $id,
            'ruc' => $id,
            'razon_social' => "Tenant de prueba {$id}",
            'giro' => 'agencia_viajes',
        ]);

        $this->tenantsCreados[] = $tenant;

        return $tenant;
    }

    public function test_agrega_permisos_faltantes_sin_pisar_personalizaciones_ni_crear_roles(): void
    {
        $tenant = $this->crearTenantDescartable();

        $tenant->run(function () {
            $role = Role::create(['guard_name' => 'api', 'name' => 'Rol De Prueba']);
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'prueba.ver']);
            $role->givePermissionTo('prueba.ver');

            // Personalización manual, fuera del catálogo — no debe tocarse.
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'permiso_custom_del_tenant']);
            $role->givePermissionTo('permiso_custom_del_tenant');
        });

        Artisan::call('roles:sync-permisos-nuevos', [
            'catalogo' => CatalogoDePruebaRoles::class,
            '--tenant' => [$tenant->id],
        ]);

        $tenant->run(function () {
            $role = Role::where('guard_name', 'api')->where('name', 'Rol De Prueba')->first();
            $permisos = $role->permissions()->pluck('name')->sort()->values()->all();

            $this->assertContains('prueba.ver', $permisos, 'El que ya tenía debe seguir.');
            $this->assertContains('prueba.crear', $permisos, 'Faltante del catálogo debe agregarse.');
            $this->assertContains('prueba.editar', $permisos, 'Faltante del catálogo debe agregarse.');
            $this->assertContains('permiso_custom_del_tenant', $permisos, 'Personalización manual NO debe pisarse (no usa syncPermissions()).');

            $this->assertNull(
                Role::where('guard_name', 'api')->where('name', 'Rol Que No Existe En Ningun Tenant')->first(),
                'El comando nunca crea un rol que el tenant no tenía sembrado.'
            );
        });
    }

    public function test_es_idempotente(): void
    {
        $tenant = $this->crearTenantDescartable();

        $tenant->run(function () {
            Role::create(['guard_name' => 'api', 'name' => 'Rol De Prueba']);
        });

        Artisan::call('roles:sync-permisos-nuevos', [
            'catalogo' => CatalogoDePruebaRoles::class,
            '--tenant' => [$tenant->id],
        ]);
        Artisan::call('roles:sync-permisos-nuevos', [
            'catalogo' => CatalogoDePruebaRoles::class,
            '--tenant' => [$tenant->id],
        ]);

        $tenant->run(function () {
            $role = Role::where('guard_name', 'api')->where('name', 'Rol De Prueba')->first();
            $this->assertCount(3, $role->permissions, 'Correr dos veces no debe duplicar ni fallar.');
        });
    }
}
