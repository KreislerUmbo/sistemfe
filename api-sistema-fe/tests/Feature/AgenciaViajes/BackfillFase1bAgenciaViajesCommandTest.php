<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Fase 1b — permisos:backfill-fase1b-agencia-viajes, contra tenant físico
// descartable con un rol real que ya tenía el permiso plano viejo (mismo
// escenario real que Admin-General en agencia-demo, confirmado en el
// diagnóstico de la Parte 2).
class BackfillFase1bAgenciaViajesCommandTest extends TestCase
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
        $id = 'test-backfill1b-' . Str::lower(Str::random(8));

        $tenant = Tenant::create([
            'id' => $id,
            'ruc' => $id,
            'razon_social' => "Tenant de prueba {$id}",
            'giro' => 'agencia_viajes',
        ]);

        $this->tenantsCreados[] = $tenant;

        return $tenant;
    }

    public function test_backfillea_un_rol_real_que_tenia_el_permiso_plano_viejo(): void
    {
        $tenant = $this->crearTenantDescartable();

        // Reproduce el escenario real de Admin-General: un rol con el
        // permiso plano viejo, sin ninguno de los granulares nuevos.
        $tenant->run(function () {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'agencia.cotizaciones']);
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'agencia.reservas']);
            $rol = Role::create(['guard_name' => 'api', 'name' => 'Admin-General']);
            $rol->givePermissionTo(['agencia.cotizaciones', 'agencia.reservas']);
        });

        Artisan::call('permisos:backfill-fase1b-agencia-viajes', ['tenant' => $tenant->id]);

        $tenant->run(function () {
            $rol = Role::where('guard_name', 'api')->where('name', 'Admin-General')->first();

            $this->assertTrue($rol->hasPermissionTo('cotizaciones.ver'));
            $this->assertTrue($rol->hasPermissionTo('cotizaciones.ver_todas'));
            $this->assertTrue($rol->hasPermissionTo('cotizaciones.crear'));
            $this->assertTrue($rol->hasPermissionTo('cotizaciones.editar'));
            $this->assertTrue($rol->hasPermissionTo('reservas.ver'));
            $this->assertTrue($rol->hasPermissionTo('reservas.ver_todas'));
            $this->assertTrue($rol->hasPermissionTo('reservas.crear'));
            $this->assertTrue($rol->hasPermissionTo('reservas.editar'));
            // El plano original sigue ahí — nunca se lo quita.
            $this->assertTrue($rol->hasPermissionTo('agencia.cotizaciones'));
        });
    }

    public function test_no_toca_roles_que_nunca_tuvieron_el_permiso_plano(): void
    {
        $tenant = $this->crearTenantDescartable();

        $tenant->run(function () {
            Role::create(['guard_name' => 'api', 'name' => 'Rol Sin Nada']);
        });

        Artisan::call('permisos:backfill-fase1b-agencia-viajes', ['tenant' => $tenant->id]);

        $tenant->run(function () {
            $rol = Role::where('guard_name', 'api')->where('name', 'Rol Sin Nada')->first();
            $this->assertCount(0, $rol->permissions);
        });
    }

    // Regresión real encontrada en verificación en vivo contra sandbox (no
    // por ningún test): el backfill escaneaba TODOS los roles con el
    // permiso plano, incluidos 'Contador'/'Vendedor de agencia' que el
    // propio AgenciaViajesRolesSeeder acaba de crear con un alcance
    // deliberadamente restringido — les daba el set completo (ver_todas/
    // crear/editar), pisando la restricción de §3.2.
    public function test_no_sobre_otorga_a_los_roles_que_el_propio_catalogo_ya_administra(): void
    {
        $tenant = $this->crearTenantDescartable();

        Artisan::call('permisos:backfill-fase1b-agencia-viajes', ['tenant' => $tenant->id]);

        $tenant->run(function () {
            $contador = Role::where('guard_name', 'api')->where('name', 'Contador')->first();
            $this->assertFalse($contador->hasPermissionTo('cotizaciones.crear'), 'Contador no debe ganar crear vía backfill (§3.2).');
            $this->assertFalse($contador->hasPermissionTo('reservas.crear'), 'Contador no debe ganar crear vía backfill (§3.2).');

            $vendedor = Role::where('guard_name', 'api')->where('name', 'Vendedor de agencia')->first();
            $this->assertFalse($vendedor->hasPermissionTo('cotizaciones.ver_todas'), 'Vendedor: alcance propio, sin ver_todas (§3.3).');
            $this->assertFalse($vendedor->hasPermissionTo('reservas.editar'), 'Vendedor sin reservas.editar (§3.2).');
        });
    }

    public function test_tambien_siembra_los_4_roles_del_catalogo(): void
    {
        $tenant = $this->crearTenantDescartable();

        Artisan::call('permisos:backfill-fase1b-agencia-viajes', ['tenant' => $tenant->id]);

        $tenant->run(function () {
            $this->assertNotNull(Role::where('guard_name', 'api')->where('name', 'Administrador de agencia')->first());
            $this->assertNotNull(Role::where('guard_name', 'api')->where('name', 'Supervisor')->first());
            $this->assertNotNull(Role::where('guard_name', 'api')->where('name', 'Vendedor de agencia')->first());
            $this->assertNotNull(Role::where('guard_name', 'api')->where('name', 'Contador')->first());
        });
    }
}
