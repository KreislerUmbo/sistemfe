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
    private const MIGRACIONES = [
        '2026_11_28_100000_create_compras_tables', '2026_11_28_100100_add_compras_permissions',
        // T-154: dependen de compras.
        '2026_11_29_100000_create_compra_lineas_table', '2026_11_29_100100_add_con_credito_fiscal_to_compras',
        // T-152: solo en Agencia, y con FK a compras.
        '2026_11_30_100000_create_compra_asignaciones_table',
        // T-162: completa compra_lineas.
        '2026_11_30_200000_add_detalle_comprobante_to_compra_lineas',
        // T-136: compras.pagar.
        '2026_12_01_100200_add_pagos_proveedor_permissions',
        // T-136: pagos y aplicaciones, con FK a compras.
        '2026_12_01_100300_create_pagos_proveedor_tables',
        // T-140: tipos de cambio y reembolsos.
        '2026_12_02_100000_add_tipo_cambio_to_pagos_proveedor',
        '2026_12_02_100100_create_reembolsos_proveedor_table',
        '2026_12_02_100200_tipo_cambio_aplicacion_seis_decimales',
    ];

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
                // T-140: apunta a pagos y a compras.
                Schema::drop('reembolsos_proveedor');
                Schema::drop('aplicaciones_pago_proveedor');
                Schema::drop('pagos_proveedor');
                Schema::dropIfExists('compra_asignaciones');
                Schema::drop('compra_periodos');
                Schema::drop('compra_lineas');
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
            // T-136
            $this->assertTrue(Schema::hasTable('pagos_proveedor'), $giro);
            $this->assertTrue(Schema::hasTable('aplicaciones_pago_proveedor'), $giro);
            // T-140
            $this->assertTrue(Schema::hasTable('reembolsos_proveedor'), $giro);
            $this->assertTrue(Schema::hasColumn('aplicaciones_pago_proveedor', 'monto_compra'), $giro);
            // T-154
            $this->assertTrue(Schema::hasTable('compra_lineas'), $giro);
            // T-152 (Q1): el reparto es propio de Agencia.
            $this->assertSame($giro === 'agencia_viajes', Schema::hasTable('compra_asignaciones'), $giro);
            $this->assertTrue(Schema::hasColumn('compras', 'con_credito_fiscal'), $giro);
            $this->assertTrue(Schema::hasColumn('products', 'costo_promedio'), $giro);
            $this->assertSame(
                ['compras.anular', 'compras.cerrar_periodo', 'compras.pagar', 'compras.registrar', 'compras.ver'],
                Permission::where('name', 'like', 'compras.%')->orderBy('name')->pluck('name')->all(),
                $giro
            );
            $this->assertNotEmpty(DB::select("select 1 from pg_indexes where schemaname = current_schema() and indexname = 'compras_comprobante_unico'"), $giro);
        });
    }
}
