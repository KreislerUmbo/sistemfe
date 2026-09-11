<?php

namespace Tests\Feature;

use App\Http\Controllers\Role\RoleController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Fase 2a (plan-modulo-menus-y-roles.md §5/§9.5) — 3 hallazgos reales
// encontrados leyendo RoleController antes de arrancar el frontend de la
// Fase 2: (1) Super-Admin, rol técnico protegido según el plan, no tenía
// ningún guard real — se podía renombrar/reconfigurar/eliminar vía API;
// (2) destroy() no tenía ningún guard anti-lockout (§9.5) — se podía
// eliminar un rol con usuarios activos asignados, dejándolos huérfanos en
// silencio; (3) GET /api/roles y GET /api/users no tenían ningún
// permission: (solo store/update/destroy quedaron gateados en Fase 0b) —
// cualquier usuario autenticado del tenant podía listar el catálogo
// completo de roles+permisos o de usuarios.
//
// Mismo patrón que GateBucketARoutesTest para la parte de ruta (verificar
// el middleware REAL registrado, no una réplica) + invocación directa del
// controller (mismo patrón que ReservaSincronizarItemsTest) para la parte
// de guards nuevos, contra sistemafe_test_migrations (Postgres real).
class RoleControllerFase2aTest extends TestCase
{
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
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_route_index_roles_exige_list_role(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/roles' && in_array('GET', $r->methods(), true)
        );

        $this->assertNotNull($route, 'No se encontró GET api/roles en el router.');

        $permisos = collect($route->gatherMiddleware())
            ->filter(fn ($m) => str_starts_with($m, 'permission:'))
            ->map(fn ($m) => substr($m, strlen('permission:')))
            ->values()->all();

        $this->assertContains('list_role', $permisos);
    }

    public function test_route_index_users_exige_list_user(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/users' && in_array('GET', $r->methods(), true)
        );

        $this->assertNotNull($route, 'No se encontró GET api/users en el router.');

        $permisos = collect($route->gatherMiddleware())
            ->filter(fn ($m) => str_starts_with($m, 'permission:'))
            ->map(fn ($m) => substr($m, strlen('permission:')))
            ->values()->all();

        $this->assertContains('list_user', $permisos);
    }

    public function test_no_permite_editar_super_admin(): void
    {
        $role = Role::create(['name' => 'Super-Admin', 'guard_name' => 'api']);

        $response = app(RoleController::class)->update(
            new Request(['name' => 'Otro nombre', 'permissions' => []]),
            (string) $role->id
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Super-Admin', $role->fresh()->name);
    }

    public function test_no_permite_eliminar_super_admin(): void
    {
        $role = Role::create(['name' => 'Super-Admin', 'guard_name' => 'api']);

        $response = app(RoleController::class)->destroy((string) $role->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotNull($role->fresh());
    }

    public function test_no_permite_eliminar_rol_con_usuarios_asignados(): void
    {
        // users.role_id (legacy) exige una fila real en roles antes de
        // poder crear un User::factory() — mismo requisito que
        // ReservaSincronizarItemsTest/GateBucketARoutesTest.
        if (! DB::table('roles')->where('id', 1)->exists()) {
            DB::table('roles')->insert([
                'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
        }

        $role = Role::create(['name' => 'Vendedor de prueba', 'guard_name' => 'api']);
        $user = User::factory()->create();
        $user->assignRole($role);

        $response = app(RoleController::class)->destroy((string) $role->id);
        $body = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('usuario(s) asignado(s)', $body['message']);
        $this->assertNotNull($role->fresh());
    }

    public function test_permite_eliminar_rol_sin_usuarios_asignados(): void
    {
        $role = Role::create(['name' => 'Rol sin uso', 'guard_name' => 'api']);

        $response = app(RoleController::class)->destroy((string) $role->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(Role::find($role->id));
    }
}
