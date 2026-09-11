<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\MenuResolver;
use App\Services\TenantProvisioningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// Hallazgo real de revisión posterior a Fase 1a (plan-modulo-menus-y-roles.md
// §4.1): cambiar el giro de un tenant vía TenantAdminController/
// TenantProvisioningService::actualizar() (ya en producción, Panel
// Superadmin) nunca invalidaba el caché de MenuResolver — un usuario que ya
// había pedido /me/menu antes del cambio seguía viendo el árbol del giro
// VIEJO hasta por 24h. Mismo patrón de tenant físico descartable que
// MigrateVerticalesPendientesTest/SyncPermisosNuevosCommandTest — es la
// única forma real de probar tenancy()->initialize() + la tabla `cache`
// aislada por tenant, no es mockeable.
//
// cache.default se fuerza a 'database' (phpunit.xml trae CACHE_STORE=array
// para toda la suite, a propósito, para no tocar infra real en el resto de
// los tests) — acá se necesita el driver real porque lo que se prueba es
// justamente el aislamiento de la tabla `cache` por tenant.
class TenantProvisioningServiceGiroCacheTest extends TestCase
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
            'cache.default' => 'database',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        app('cache')->forgetDriver();

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

    private function crearTenantDescartable(string $giro): Tenant
    {
        $id = 'test-girocache-' . Str::lower(Str::random(8));

        $tenant = Tenant::create([
            'id' => $id,
            'ruc' => $id,
            'razon_social' => "Tenant de prueba {$id}",
            'giro' => $giro,
        ]);

        $this->tenantsCreados[] = $tenant;

        return $tenant;
    }

    private function crearUsuarioYPrecalentarCache(Tenant $tenant): int
    {
        return $tenant->run(function () {
            DB::table('roles')->insert([
                'id' => 1,
                'name' => 'test-role-default',
                'guard_name' => 'api',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

            $user = User::factory()->create();

            // Precalienta el caché de menú con el giro VIEJO — mismo efecto
            // que un usuario real pidiendo /me/menu antes del cambio.
            app(MenuResolver::class)->paraUsuario($user, tenant());

            return $user->id;
        });
    }

    public function test_cambiar_giro_invalida_el_cache_de_menu_del_tenant(): void
    {
        $tenant = $this->crearTenantDescartable('retail');
        $userId = $this->crearUsuarioYPrecalentarCache($tenant);

        $cacheAntes = $tenant->run(fn () => Cache::get("menu:{$tenant->id}:{$userId}"));
        $this->assertNotNull($cacheAntes, 'Precondición: el caché debe estar poblado antes del cambio de giro.');

        app(TenantProvisioningService::class)->actualizar($tenant, ['giro' => 'agencia_viajes']);

        $this->assertSame('agencia_viajes', $tenant->fresh()->giro);

        $cacheDespues = $tenant->run(fn () => Cache::get("menu:{$tenant->id}:{$userId}"));
        $this->assertNull($cacheDespues, 'El caché de menú del tenant debe quedar invalidado tras cambiar el giro.');
    }

    public function test_cambiar_otro_campo_sin_tocar_giro_no_invalida_el_cache(): void
    {
        $tenant = $this->crearTenantDescartable('retail');
        $userId = $this->crearUsuarioYPrecalentarCache($tenant);

        app(TenantProvisioningService::class)->actualizar($tenant, ['razon_social' => 'Nuevo Nombre SAC']);

        $cacheDespues = $tenant->run(fn () => Cache::get("menu:{$tenant->id}:{$userId}"));
        $this->assertNotNull($cacheDespues, 'Un cambio que no toca el giro no debe invalidar el caché de menú.');
    }
}
