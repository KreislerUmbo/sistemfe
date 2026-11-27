<?php

namespace Tests\Feature\Compras;

use App\Models\Tenant;
use App\Models\CuentaBancaria;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// T-136, CA1 y CA2 — `cuentas_bancarias` en el sistema base. Mismo patrón que
// ComprasMigracionesPorGiroTest: tenants descartables con base física propia.
class CuentasBancariasCoreTest extends TestCase
{
    private const MIGRACIONES = [
        '2026_12_01_100000_create_cuentas_bancarias_table_core',
        '2026_12_01_100100_add_moneda_to_cuentas_bancarias',
        '2026_12_01_100200_add_pagos_proveedor_permissions',
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

    private function crearTenant(string $giro): Tenant
    {
        $id = 'test-cta-'.Str::lower(Str::random(8));
        $this->tenantsCreados[] = $tenant = Tenant::create(['id' => $id, 'ruc' => $id, 'razon_social' => $id, 'giro' => $giro]);
        (new ReflectionMethod(TenantProvisioningService::class, 'migrarVertical'))
            ->invoke(app(TenantProvisioningService::class), $tenant, $giro);

        return $tenant;
    }

    // CA1: tenant nuevo de cada giro (en agencia corren core/ y después el vertical).
    public function test_ca1_cada_giro_nuevo_tiene_cuentas_bancarias_con_moneda(): void
    {
        foreach (['retail', 'creditos', 'agencia_viajes'] as $giro) {
            $this->crearTenant($giro)->run(function () use ($giro) {
                $this->assertTrue(Schema::hasColumns('cuentas_bancarias', ['banco', 'cci', 'orden', 'moneda']), $giro);
            });
        }
    }

    // CA1 y CA2: agencia ya provisionada, con cuentas y un rol que las gestionaba.
    public function test_ca1_ca2_agencia_existente_conserva_sus_cuentas_y_sus_permisos(): void
    {
        $tenant = $this->crearTenant('agencia_viajes');

        $tenant->run(function () {
            // Como antes de T-136: sin moneda ni permisos nuevos.
            Schema::table('cuentas_bancarias', fn ($t) => $t->dropColumn('moneda'));
            Permission::whereIn('name', ['compras.pagar', 'cuentas_bancarias.gestionar'])->delete();
            DB::table('migrations')->whereIn('migration', self::MIGRACIONES)->delete();
            DB::table('cuentas_bancarias')->insert(['banco' => 'BCP', 'titular' => 'Agencia', 'numero_cuenta' => '123']);

            app()[PermissionRegistrar::class]->forgetCachedPermissions();
            $rol = Role::create(['name' => 'T136 Configura', 'guard_name' => 'api']);
            $rol->givePermissionTo(Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'agencia.configuracion']));
            Role::create(['name' => 'T136 Vendedor', 'guard_name' => 'api']);
        });

        $this->assertSame(0, Artisan::call('tenants:migrate-verticales'));

        $tenant->run(function () {
            $cuenta = CuentaBancaria::sole();
            $this->assertSame('123', $cuenta->numero_cuenta);
            $this->assertNull($cuenta->moneda, 'la moneda no se supone');

            app()[PermissionRegistrar::class]->forgetCachedPermissions();
            $this->assertTrue(Role::findByName('T136 Configura', 'api')->hasPermissionTo('cuentas_bancarias.gestionar'));
            $this->assertFalse(Role::findByName('T136 Vendedor', 'api')->hasPermissionTo('cuentas_bancarias.gestionar'));
            $this->assertFalse(Role::findByName('T136 Configura', 'api')->hasPermissionTo('compras.pagar'));
        });
    }

    public function test_ca2_las_rutas_exigen_el_permiso_nuevo_y_no_el_giro(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/cuentas-bancarias'));

        $this->assertCount(4, $rutas);
        foreach ($rutas as $ruta) {
            $this->assertContains('permission:cuentas_bancarias.gestionar', $ruta->gatherMiddleware(), $ruta->uri());
            $this->assertNotContains('permission:agencia.configuracion', $ruta->gatherMiddleware(), $ruta->uri());
        }
    }
}
