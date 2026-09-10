<?php

namespace Tests\Feature;

use App\Http\Controllers\MenuController;
use App\Models\Central\MenuItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MenuResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// Fase 1a (plan-modulo-menus-y-roles.md §4.2) — capa 1 (ruta registrada,
// mismo criterio que GateBucketARoutesTest) + capa 2 end-to-end contra un
// tenant físico real y descartable (única forma de probar que tenant()/
// auth('api')->user() resuelven bien DENTRO de MenuController, ya que esas
// dos cosas solo existen con tenancy realmente inicializada — mismo patrón
// que SyncPermisosNuevosCommandTest/MigrateVerticalesPendientesTest).
class MenuControllerTest extends TestCase
{
    /** @var Tenant[] */
    private array $tenantsCreados = [];

    private array $menuItemIdsCreados = [];

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
        MenuItem::whereIn('id', $this->menuItemIdsCreados)->delete();

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

    private function crearTenantDescartable(string $giro = 'agencia_viajes'): Tenant
    {
        $id = 'test-menuctrl-' . Str::lower(Str::random(8));

        $tenant = Tenant::create([
            'id' => $id,
            'ruc' => $id,
            'razon_social' => "Tenant de prueba {$id}",
            'giro' => $giro,
        ]);

        $this->tenantsCreados[] = $tenant;

        return $tenant;
    }

    public function test_ruta_me_menu_esta_registrada_dentro_del_grupo_autenticado(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/me/menu' && in_array('GET', $r->methods(), true)
        );

        $this->assertNotNull($route, 'GET api/me/menu no está registrada.');

        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth:api', $middleware);
        $this->assertContains('tenant', $middleware);
    }

    public function test_responde_el_arbol_del_usuario_real_contra_un_tenant_fisico(): void
    {
        $tenant = $this->crearTenantDescartable();

        $item = MenuItem::create(['codigo' => 'test_e2e', 'tipo' => 'enlace', 'label' => 'E2E', 'ruta' => 'e2e.index', 'permiso_requerido' => 'test_e2e.ver', 'orden' => 1]);
        $this->menuItemIdsCreados[] = $item->id;

        $respuesta = $tenant->run(function () {
            DB::table('roles')->insert([
                'id' => 1,
                'name' => 'test-role-default',
                'guard_name' => 'api',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

            $user = User::factory()->create();
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'test_e2e.ver']);
            $user->givePermissionTo('test_e2e.ver');
            Auth::guard('api')->setUser($user->fresh());

            return app(MenuController::class)->miMenu();
        });

        $data = $respuesta->getData(true);

        $this->assertArrayHasKey('menu', $data);
        $this->assertSame(['test_e2e'], array_column($data['menu'], 'codigo'));
    }
}
