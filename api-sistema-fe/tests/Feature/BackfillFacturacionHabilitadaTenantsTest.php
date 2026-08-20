<?php

namespace Tests\Feature;

use App\Models\Sale\Sale;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// tenants:backfill-facturacion-habilitada (PEGAR-EN-CLAUDE-CODE-facturacion-
// externa-tenant.md §1) — mismo patrón que MigrateVerticalesPendientesTest:
// los tenants de este test SÍ crean bases físicas reales (Tenant::create()
// dispara CreateDatabase + MigrateDatabase síncrono), es la única forma real
// de probar $tenant->run(fn () => Sale::exists()). tearDown() borra siempre
// las bases descartables, nunca deben quedar huérfanas.
class BackfillFacturacionHabilitadaTenantsTest extends TestCase
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
                'database/migrations/2026_08_20_150000_add_facturacion_habilitada_to_tenants_table.php',
            ],
            '--force' => true,
        ]);
    }

    private function crearTenantDescartable(array $overrides = []): Tenant
    {
        $id = 'test-backfill-' . Str::lower(Str::random(8));

        $tenant = Tenant::create(array_merge([
            'id' => $id,
            'ruc' => $id,
            'razon_social' => "Tenant de prueba {$id}",
            'giro' => 'retail',
        ], $overrides));

        $this->tenantsCreados[] = $tenant;

        return $tenant;
    }

    // users.role_id tiene default(1) a nivel de Postgres (FK real hacia
    // roles) — un tenant físico recién creado por Tenant::create() directo
    // (sin pasar por TenantProvisioningService::provision(), que sí siembra
    // roles reales) no tiene ninguna fila en `roles` todavía. Sale::factory()
    // crea un User::factory() de paso y revienta sin esto (mismo fixture que
    // el resto de la suite, aplicado acá dentro de la conexión física del
    // tenant).
    private function crearSaleReal(Tenant $tenant): void
    {
        $tenant->run(function () {
            if (! DB::table('roles')->where('id', 1)->exists()) {
                DB::table('roles')->insert([
                    'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            Sale::factory()->create();
        });
    }

    public function test_dry_run_no_modifica_nada_y_apply_marca_true_al_tenant_con_sale(): void
    {
        $tenantConSale = $this->crearTenantDescartable(['giro' => 'agencia_viajes']);
        $this->crearSaleReal($tenantConSale);

        $tenantSinSaleAgencia = $this->crearTenantDescartable(['giro' => 'agencia_viajes']);

        // Dry-run (sin --apply): ningún tenant debe cambiar.
        Artisan::call('tenants:backfill-facturacion-habilitada');
        $this->assertNull($tenantConSale->fresh()->facturacion_habilitada);
        $this->assertNull($tenantSinSaleAgencia->fresh()->facturacion_habilitada);

        // --apply: el que tiene Sale real pasa a true; el agencia_viajes sin
        // historial es el caso ambiguo, queda NULL para decisión manual.
        Artisan::call('tenants:backfill-facturacion-habilitada', ['--apply' => true]);
        $this->assertTrue($tenantConSale->fresh()->facturacion_habilitada);
        $this->assertNull($tenantSinSaleAgencia->fresh()->facturacion_habilitada);
    }

    public function test_apply_marca_true_por_defecto_a_giro_distinto_de_agencia_viajes_sin_historial(): void
    {
        $tenantRetailSinSale = $this->crearTenantDescartable(['giro' => 'retail']);

        Artisan::call('tenants:backfill-facturacion-habilitada', ['--apply' => true]);

        $this->assertTrue(
            $tenantRetailSinSale->fresh()->facturacion_habilitada,
            'giro != agencia_viajes sin historial debe pasar a true por defecto, no queda NULL.'
        );
    }

    public function test_es_idempotente_no_pisa_tenant_ya_seteado(): void
    {
        $tenantYaSeteado = $this->crearTenantDescartable(['facturacion_habilitada' => false]);
        $this->crearSaleReal($tenantYaSeteado);

        Artisan::call('tenants:backfill-facturacion-habilitada', ['--apply' => true]);

        $this->assertFalse($tenantYaSeteado->fresh()->facturacion_habilitada, 'No debe pisar un valor ya decidido, aunque tenga Sale real.');
    }
}
