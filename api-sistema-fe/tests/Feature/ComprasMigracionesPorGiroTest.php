<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// T-135, CA10 — las tablas y permisos de Compras llegan a un tenant de cada giro, tanto a uno
// nuevo como a uno ya provisionado (tenants:migrate-verticales). Mismo patrón que
// ProveedoresTablaBaseTest: tenants descartables con base física propia.
class ComprasMigracionesPorGiroTest extends TestCase
{
    private const MIGRACIONES = ['2026_11_28_100000_create_compras_tables', '2026_11_28_100100_add_compras_permissions'];

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

    public function test_ca10_cada_giro_recibe_las_tablas_y_los_permisos_de_compras(): void
    {
        foreach (['retail', 'agencia_viajes', 'creditos'] as $giro) {
            $id = 'test-com-'.Str::lower(Str::random(8));
            $this->tenantsCreados[] = $tenant = Tenant::create(['id' => $id, 'ruc' => $id, 'razon_social' => $id, 'giro' => $giro]);
            (new ReflectionMethod(TenantProvisioningService::class, 'migrarVertical'))
                ->invoke(app(TenantProvisioningService::class), $tenant, $giro);

            $this->comprobar($tenant, $giro);

            // Como un tenant anterior a T-135: le faltan y se las trae el comando del deploy.
            $tenant->run(function () {
                Schema::drop('compra_periodos');
                Schema::drop('compras');
                Permission::where('name', 'like', 'compras.%')->delete();
                DB::table('migrations')->whereIn('migration', self::MIGRACIONES)->delete();
            });
        }

        $this->assertSame(0, Artisan::call('tenants:migrate-verticales'));

        foreach ($this->tenantsCreados as $tenant) {
            $this->comprobar($tenant, $tenant->giro);
        }
    }

    private function comprobar(Tenant $tenant, string $giro): void
    {
        $tenant->run(function () use ($giro) {
            $this->assertTrue(Schema::hasTable('compras'), $giro);
            $this->assertTrue(Schema::hasTable('compra_periodos'), $giro);
            $this->assertSame(
                ['compras.anular', 'compras.cerrar_periodo', 'compras.registrar', 'compras.ver'],
                Permission::where('name', 'like', 'compras.%')->orderBy('name')->pluck('name')->all(),
                $giro
            );
            $this->assertNotEmpty(DB::select("select 1 from pg_indexes where schemaname = current_schema() and indexname = 'compras_comprobante_unico'"), $giro);
        });
    }
}
