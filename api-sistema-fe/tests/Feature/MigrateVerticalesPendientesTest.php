<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

// tenants:migrate-verticales (rama fix/infra-migracion-verticals-pendientes) — prueba
// de extremo a extremo contra Postgres real: crea 2 tenants centrales reales y
// descartables (uno agencia_viajes, uno retail), SIN correr sus migraciones de
// vertical primero — mismo estado "atrasado" que dejaba el bug de
// config/tenancy.php migration_parameters (--path hardcodeado a tenant/core/, así que
// `tenants:migrate` a secas nunca corría tenant/verticals/*). Corre el comando nuevo y
// confirma en la base física de cada tenant que agencia_viajes quedó al día y que
// retail no recibió ninguna tabla que no le corresponde.
//
// 'central' se redirige a sistemafe_test_migrations (mismo patrón que
// TiposComprobanteCatalogTest) — nunca contra db_tenant_central real (sandbox/umbo/
// negocio2/agencia-demo). tenants/domains no existían ahí todavía: se agregan una sola
// vez (idempotente, guard con Schema::hasTable) corriendo las migraciones reales del
// proyecto, no un schema inventado a mano.
//
// Los 2 tenants de este test SÍ crean bases físicas reales (Tenant::create() dispara
// CreateDatabase + MigrateDatabase síncrono, igual que en producción) — es la única
// forma de probar de verdad que las tablas de vertical existen en la base del tenant,
// no es mockeable. tearDown() las borra siempre (try/finally implícito vía la lista
// acumulada), nunca deben quedar databases huérfanas.
class MigrateVerticalesPendientesTest extends TestCase
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

    // tenants/domains no forman parte de sistemafe_test_migrations (esa base excluyó a
    // propósito las 3 migraciones de stancl/tenancy que hardcodean la conexión
    // 'central' — ver CLAUDE.md, sección de infraestructura de testing). Se agregan
    // acá con un guard de idempotencia: la primera corrida de este test las suma a la
    // base compartida, las siguientes no vuelven a tocarlas.
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
        $id = 'test-vert-' . Str::lower(Str::random(8));

        $tenant = Tenant::create([
            'id' => $id,
            'ruc' => $id,
            'razon_social' => "Tenant de prueba {$id}",
            'giro' => $giro,
        ]);

        $this->tenantsCreados[] = $tenant;

        return $tenant;
    }

    public function test_giro_agencia_viajes_queda_al_dia_y_retail_no_recibe_tablas_ajenas(): void
    {
        $agencia = $this->crearTenantDescartable('agencia_viajes');
        $retail = $this->crearTenantDescartable('retail');

        // Precondición: tenant/core/ ya corrió (Tenant::create() lo hace síncrono),
        // tenant/verticals/agencia-viajes/ todavía no — exactamente el estado que
        // dejaba `tenants:migrate` a secas antes de este fix.
        $this->assertFalse(
            $agencia->run(fn () => Schema::hasTable('paquetes_plantilla')),
            'Precondición: antes de correr el comando, agencia_viajes no debería tener todavía las tablas de vertical.'
        );

        Artisan::call('tenants:migrate-verticales');

        $this->assertTrue(
            $agencia->run(fn () => Schema::hasTable('paquetes_plantilla')),
            'tenants:migrate-verticales debió aplicar tenant/verticals/agencia-viajes/ al tenant agencia_viajes.'
        );
        $this->assertTrue($agencia->run(fn () => Schema::hasTable('configuracion_agencia')));

        $this->assertFalse(
            $retail->run(fn () => Schema::hasTable('paquetes_plantilla')),
            'El tenant retail no tiene carpeta de vertical propia — no debe recibir ninguna tabla de agencia_viajes.'
        );
    }
}
