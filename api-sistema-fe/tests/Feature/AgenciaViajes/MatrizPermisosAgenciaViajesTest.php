<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Fase 1b (plan-modulo-menus-y-roles.md §9.8) — test de matriz de permisos:
// 4 roles de negocio (Super-Admin queda afuera, bypasea todo vía
// Gate::before()) × cada recurso del catálogo de agencia_viajes × acción
// esperada (allow/deny), contra el catálogo REAL sembrado por
// AgenciaViajesRolesSeeder — no una copia paralela de la matriz, así un
// cambio futuro al seeder que rompa §3.2/§3.3 se detecta acá.
//
// Queda en la suite para que cualquier fase futura que toque roles/
// permisos de agencia_viajes la corra antes de mergear.
class MatrizPermisosAgenciaViajesTest extends TestCase
{
    private static ?Tenant $tenant = null;

    /** @var Tenant[] */
    private static array $tenantsCreados = [];

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
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$tenantsCreados as $tenant) {
            $tenant = $tenant->fresh();

            if (! $tenant) {
                continue;
            }

            if ($tenant->database()->manager()->databaseExists($tenant->database()->getName())) {
                $tenant->database()->manager()->deleteDatabase($tenant);
            }

            $tenant->delete();
        }
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

    // Un solo tenant provisionado, reusado por todas las filas de la
    // matriz (evita crear ~40 bases físicas — provision() ya se probó a
    // fondo en AgenciaViajesRolesSeederTest, acá solo se lee lo que sembró).
    private function tenant(): Tenant
    {
        if (self::$tenant !== null) {
            return self::$tenant;
        }

        $id = 'test-matriz-' . Str::lower(Str::random(8));

        self::$tenant = app(TenantProvisioningService::class)->provision([
            'ruc' => $id,
            'razon_social' => "Tenant matriz {$id}",
            'razon_social_comercial' => "Tenant matriz {$id}",
            'domain' => $id,
            'admin_name' => 'Admin Test',
            'admin_email' => "admin-{$id}@test.local",
            'admin_password' => 'password123',
            'giro' => 'agencia_viajes',
            'facturacion_habilitada' => false,
        ]);
        self::$tenantsCreados[] = self::$tenant;

        return self::$tenant;
    }

    public static function matrizProvider(): array
    {
        $roles = ['Administrador de agencia', 'Supervisor', 'Vendedor de agencia', 'Contador'];

        // permiso => [roles que DEBEN tenerlo]
        $esperado = [
            // Cotizaciones (§3.2)
            'cotizaciones.ver' => ['Administrador de agencia', 'Supervisor', 'Vendedor de agencia'],
            'cotizaciones.ver_todas' => ['Administrador de agencia', 'Supervisor', 'Contador'],
            'cotizaciones.crear' => ['Administrador de agencia', 'Supervisor', 'Vendedor de agencia'],
            'cotizaciones.editar' => ['Administrador de agencia', 'Supervisor', 'Vendedor de agencia'],
            // Reservas (§3.2)
            'reservas.ver' => ['Administrador de agencia', 'Supervisor', 'Vendedor de agencia'],
            'reservas.ver_todas' => ['Administrador de agencia', 'Supervisor', 'Contador'],
            'reservas.crear' => ['Administrador de agencia', 'Supervisor', 'Vendedor de agencia'],
            'reservas.editar' => ['Administrador de agencia', 'Supervisor'], // Vendedor NO (§3.2)
            // Notas de Crédito/Débito — sin roles.administrar, Vendedor no las toca (§3.2)
            'nota_electronica' => ['Administrador de agencia', 'Supervisor', 'Contador'],
            // Caja
            'cash.open_session' => ['Administrador de agencia', 'Supervisor', 'Vendedor de agencia'],
            'cash.view_all' => ['Administrador de agencia', 'Supervisor', 'Contador'],
            // Configuración/gestión — exclusivo de Administrador (§3.2/§5)
            'roles.administrar' => ['Administrador de agencia'],
            'agencia.configuracion' => ['Administrador de agencia'],
            'company' => ['Administrador de agencia'],
            // Maestros del giro — Administrador/Supervisor, no Vendedor/Contador (§3.2)
            'agencia.proveedores' => ['Administrador de agencia', 'Supervisor'],
            'agencia.destinos' => ['Administrador de agencia', 'Supervisor'],
        ];

        $filas = [];
        foreach ($esperado as $permiso => $rolesConAcceso) {
            foreach ($roles as $rol) {
                $debeTener = in_array($rol, $rolesConAcceso, true);
                $filas["{$rol} → {$permiso} = " . ($debeTener ? 'allow' : 'deny')] = [$rol, $permiso, $debeTener];
            }
        }

        return $filas;
    }

    #[DataProvider('matrizProvider')]
    public function test_matriz_de_permisos(string $rol, string $permiso, bool $debeTener): void
    {
        $tenant = $this->tenant();

        $tenant->run(function () use ($rol, $permiso, $debeTener) {
            $role = Role::where('guard_name', 'api')->where('name', $rol)->first();

            $this->assertNotNull($role, "Rol '{$rol}' no existe en el catálogo sembrado.");

            if ($debeTener) {
                $this->assertTrue(
                    $role->hasPermissionTo($permiso),
                    "'{$rol}' debería tener '{$permiso}' (§3.2/§3.3) pero no lo tiene."
                );
            } else {
                $this->assertFalse(
                    $role->hasPermissionTo($permiso),
                    "'{$rol}' NO debería tener '{$permiso}' (§3.2/§3.3) pero lo tiene."
                );
            }
        });
    }
}
