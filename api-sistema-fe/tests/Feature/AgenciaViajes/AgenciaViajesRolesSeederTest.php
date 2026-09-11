<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Fase 1b (plan-modulo-menus-y-roles.md §3.2/§7) — provisiona un tenant
// agencia_viajes físico y descartable de punta a punta (mismo patrón que
// MigrateVerticalesPendientesTest/SyncPermisosNuevosCommandTest) y confirma
// que AgenciaViajesRolesSeeder corrió sobre él vía
// TenantProvisioningService::provision() — es la única forma real de
// probar el enganche completo, no es mockeable.
class AgenciaViajesRolesSeederTest extends TestCase
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

    public function test_provision_agencia_viajes_siembra_los_4_roles_nuevos_con_sus_permisos(): void
    {
        $domain = 'test-fase1b-' . Str::lower(Str::random(8));

        $tenant = app(TenantProvisioningService::class)->provision([
            'ruc' => $domain,
            'razon_social' => 'Agencia Test SAC',
            'razon_social_comercial' => 'Agencia Test',
            'domain' => $domain,
            'admin_name' => 'Admin Test',
            'admin_email' => "admin-{$domain}@test.local",
            'admin_password' => 'password123',
            'giro' => 'agencia_viajes',
            'facturacion_habilitada' => false,
        ]);
        $this->tenantsCreados[] = $tenant;

        $tenant->run(function () {
            $admin = Role::where('guard_name', 'api')->where('name', 'Administrador de agencia')->first();
            $this->assertNotNull($admin, 'Administrador de agencia debe existir.');
            $this->assertTrue($admin->hasPermissionTo('cotizaciones.ver_todas'));
            $this->assertTrue($admin->hasPermissionTo('reservas.editar'));
            $this->assertTrue($admin->hasPermissionTo('roles.administrar'));
            $this->assertTrue($admin->hasPermissionTo('register_branch'), 'Administra Configuración del tenant.');

            $supervisor = Role::where('guard_name', 'api')->where('name', 'Supervisor')->first();
            $this->assertNotNull($supervisor);
            $this->assertTrue($supervisor->hasPermissionTo('reservas.ver_todas'));
            $this->assertFalse($supervisor->hasPermissionTo('roles.administrar'), 'Supervisor NO administra roles (§3.2).');
            $this->assertFalse($supervisor->hasPermissionTo('agencia.configuracion'), 'Supervisor NO tiene Configuración del tenant (§3.2).');

            $vendedor = Role::where('guard_name', 'api')->where('name', 'Vendedor de agencia')->first();
            $this->assertNotNull($vendedor);
            $this->assertTrue($vendedor->hasPermissionTo('cotizaciones.crear'));
            $this->assertTrue($vendedor->hasPermissionTo('reservas.crear'));
            $this->assertFalse($vendedor->hasPermissionTo('cotizaciones.ver_todas'), 'Vendedor: alcance propio, sin ver_todas (§3.3).');
            $this->assertFalse($vendedor->hasPermissionTo('reservas.editar'), 'Vendedor sin reservas.editar (§3.2).');

            $contador = Role::where('guard_name', 'api')->where('name', 'Contador')->first();
            $this->assertNotNull($contador);
            $this->assertTrue($contador->hasPermissionTo('cotizaciones.ver_todas'));
            $this->assertFalse($contador->hasPermissionTo('cotizaciones.crear'), 'Contador no opera el flujo comercial (§3.2).');
        });
    }

    public function test_provision_agrega_permisos_de_agencia_al_rol_contador_ya_creado_por_permissionsdemoseeder_sin_pisarlo(): void
    {
        $domain = 'test-fase1b-' . Str::lower(Str::random(8));

        $tenant = app(TenantProvisioningService::class)->provision([
            'ruc' => $domain,
            'razon_social' => 'Agencia Test SAC 2',
            'razon_social_comercial' => 'Agencia Test 2',
            'domain' => $domain,
            'admin_name' => 'Admin Test',
            'admin_email' => "admin-{$domain}@test.local",
            'admin_password' => 'password123',
            'giro' => 'agencia_viajes',
            'facturacion_habilitada' => false,
        ]);
        $this->tenantsCreados[] = $tenant;

        $tenant->run(function () {
            $contador = Role::where('guard_name', 'api')->where('name', 'Contador')->first();

            $this->assertNotNull($contador);
            // Permisos de PermissionsDemoSeeder (el "Contador" retail original) — deben seguir.
            $this->assertTrue($contador->hasPermissionTo('register_guia_remision'));
            $this->assertTrue($contador->hasPermissionTo('nota_electronica'));
            // Permisos nuevos de agencia_viajes — agregados encima, sin pisar los de arriba.
            $this->assertTrue($contador->hasPermissionTo('cotizaciones.ver_todas'));
            $this->assertTrue($contador->hasPermissionTo('reservas.ver_todas'));
        });
    }

    public function test_es_idempotente_correrlo_dos_veces_no_duplica_ni_falla(): void
    {
        $domain = 'test-fase1b-' . Str::lower(Str::random(8));

        $tenant = app(TenantProvisioningService::class)->provision([
            'ruc' => $domain,
            'razon_social' => 'Agencia Test SAC 3',
            'razon_social_comercial' => 'Agencia Test 3',
            'domain' => $domain,
            'admin_name' => 'Admin Test',
            'admin_email' => "admin-{$domain}@test.local",
            'admin_password' => 'password123',
            'giro' => 'agencia_viajes',
            'facturacion_habilitada' => false,
        ]);
        $this->tenantsCreados[] = $tenant;

        $tenant->run(function () {
            (new \Database\Seeders\AgenciaViajesRolesSeeder())->run();
            (new \Database\Seeders\AgenciaViajesRolesSeeder())->run();

            $admin = Role::where('guard_name', 'api')->where('name', 'Administrador de agencia')->first();
            $this->assertCount(1, Role::where('guard_name', 'api')->where('name', 'Administrador de agencia')->get());
            $this->assertCount(
                count(array_unique($admin->permissions()->pluck('name')->all())),
                $admin->permissions()->pluck('name')->all(),
                'No debe haber permisos duplicados en la tabla pivote.'
            );
        });
    }
}
