<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\Client\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\CotizacionPolicy;
use App\Policies\ReservaPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Fase 1b (plan-modulo-menus-y-roles.md §3.3) — EscopablePorVendedor +
// Policies, contra un tenant físico agencia_viajes real y descartable
// (única forma de probar la migración vendedor_id + el Global Scope +
// tenancy juntos, mismo patrón que el resto de Fase 1b).
class EscopablePorVendedorTest extends TestCase
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

    // Tenant::create() a secas solo corre tenant/core/ (config
    // tenancy.migration_parameters, hardcodeado) — las tablas del vertical
    // (cotizaciones/alternativas/reserva) necesitan provision() completo,
    // que sí llama migrarVertical(). Mismo motivo por el que
    // AgenciaViajesRolesSeederTest ya usa provision(), no Tenant::create().
    private function crearTenantDescartable(): Tenant
    {
        $id = 'test-escope-' . Str::lower(Str::random(8));

        $tenant = app(\App\Services\TenantProvisioningService::class)->provision([
            'ruc' => $id,
            'razon_social' => "Tenant de prueba {$id}",
            'razon_social_comercial' => "Tenant de prueba {$id}",
            'domain' => $id,
            'admin_name' => 'Admin Test',
            'admin_email' => "admin-{$id}@test.local",
            'admin_password' => 'password123',
            'giro' => 'agencia_viajes',
            'facturacion_habilitada' => false,
        ]);

        $this->tenantsCreados[] = $tenant;

        return $tenant;
    }

    private function usuarioConPermiso(string $permiso = null): User
    {
        $user = User::factory()->create();

        if ($permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            $user->givePermissionTo($permiso);
        }

        return $user->fresh();
    }

    private function crearCotizacion(?int $vendedorId): Cotizacion
    {
        $cliente = Client::create(['name' => 'Cliente Test', 'n_document' => (string) random_int(10000000, 99999999), 'type_document' => 'dni']);

        return Cotizacion::create([
            'codigo_prefijo' => 'COT',
            'codigo' => 'COT-TEST-' . Str::random(6),
            'cliente_id' => $cliente->id,
            'vendedor_id' => $vendedorId,
            'destino' => 'Cusco',
        ]);
    }

    public function test_vendedor_sin_ver_todas_solo_ve_sus_propias_cotizaciones_en_listado(): void
    {
        $tenant = $this->crearTenantDescartable();

        $tenant->run(function () {
            $vendedorA = $this->usuarioConPermiso();
            $vendedorB = $this->usuarioConPermiso();

            $propia = $this->crearCotizacion($vendedorA->id);
            $ajena = $this->crearCotizacion($vendedorB->id);

            Auth::guard('api')->setUser($vendedorA);

            $visibles = Cotizacion::propias()->pluck('id')->all();

            $this->assertContains($propia->id, $visibles);
            $this->assertNotContains($ajena->id, $visibles);
        });
    }

    public function test_usuario_con_ver_todas_ve_todas_las_cotizaciones(): void
    {
        $tenant = $this->crearTenantDescartable();

        $tenant->run(function () {
            $vendedorA = $this->usuarioConPermiso();
            $administrador = $this->usuarioConPermiso('cotizaciones.ver_todas');

            $vendedorB = $this->usuarioConPermiso();
            $propia = $this->crearCotizacion($vendedorA->id);
            $ajena = $this->crearCotizacion($vendedorB->id);

            Auth::guard('api')->setUser($administrador);

            $visibles = Cotizacion::propias()->pluck('id')->all();

            $this->assertContains($propia->id, $visibles);
            $this->assertContains($ajena->id, $visibles);
        });
    }

    public function test_cotizacion_sin_dueno_solo_visible_para_ver_todas(): void
    {
        $tenant = $this->crearTenantDescartable();

        $tenant->run(function () {
            $sinDueno = $this->crearCotizacion(null);

            $vendedor = $this->usuarioConPermiso();
            Auth::guard('api')->setUser($vendedor);
            $this->assertNotContains($sinDueno->id, Cotizacion::propias()->pluck('id')->all());

            $administrador = $this->usuarioConPermiso('cotizaciones.ver_todas');
            Auth::guard('api')->setUser($administrador);
            $this->assertContains($sinDueno->id, Cotizacion::propias()->pluck('id')->all());
        });
    }

    public function test_policy_bloquea_view_de_una_cotizacion_ajena(): void
    {
        $tenant = $this->crearTenantDescartable();

        $tenant->run(function () {
            $vendedorA = $this->usuarioConPermiso();
            $vendedorB = $this->usuarioConPermiso();
            $ajena = $this->crearCotizacion($vendedorB->id);

            $policy = new CotizacionPolicy();

            $this->assertFalse($policy->view($vendedorA, $ajena));
            $this->assertTrue($policy->view($vendedorB, $ajena));
        });
    }

    public function test_reserva_hereda_el_scope_de_su_cotizacion_padre_via_join(): void
    {
        $tenant = $this->crearTenantDescartable();

        $tenant->run(function () {
            $vendedorA = $this->usuarioConPermiso();
            $vendedorB = $this->usuarioConPermiso();

            $cotizacionA = $this->crearCotizacion($vendedorA->id);
            $altA = Alternativa::create(['cotizacion_id' => $cotizacionA->id, 'nombre' => 'Alternativa A', 'estado' => 'aceptada', 'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia']);
            $reservaA = Reserva::create(['alternativa_id' => $altA->id, 'estado' => 'activa']);

            $cotizacionB = $this->crearCotizacion($vendedorB->id);
            $altB = Alternativa::create(['cotizacion_id' => $cotizacionB->id, 'nombre' => 'Alternativa B', 'estado' => 'aceptada', 'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia']);
            $reservaB = Reserva::create(['alternativa_id' => $altB->id, 'estado' => 'activa']);

            Auth::guard('api')->setUser($vendedorA);
            $visibles = Reserva::propias()->pluck('id')->all();

            $this->assertContains($reservaA->id, $visibles);
            $this->assertNotContains($reservaB->id, $visibles);

            $policy = new ReservaPolicy();
            $this->assertFalse($policy->view($vendedorA, $reservaB->fresh()->load('alternativa.cotizacion')));
        });
    }
}
