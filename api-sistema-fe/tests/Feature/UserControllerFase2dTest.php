<?php

namespace Tests\Feature;

use App\Http\Controllers\User\UserController;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Fase 2d (plan-modulo-menus-y-roles.md §5) — pestaña "Permisos directos"
// en el CRUD de Usuarios. Spatie ya soporta permisos asignados DIRECTO a
// un usuario, además de los que le da su rol (el proyecto ya lo usaba a
// mano vía tinker en Caja) — esto lo expone desde la API/UI.
class UserControllerFase2dTest extends TestCase
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

        if (! DB::table('roles')->where('id', 1)->exists()) {
            DB::table('roles')->insert([
                'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
        }
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_route_permisos_directos_exige_edit_user(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/users/{id}/permisos' && in_array('PUT', $r->methods(), true)
        );

        $this->assertNotNull($route, 'No se encontró PUT api/users/{id}/permisos en el router.');

        $permisos = collect($route->gatherMiddleware())
            ->filter(fn ($m) => str_starts_with($m, 'permission:'))
            ->map(fn ($m) => substr($m, strlen('permission:')))
            ->values()->all();

        $this->assertContains('edit_user', $permisos);
    }

    public function test_permisos_directos_sincroniza_sin_tocar_el_rol(): void
    {
        $role = Role::create(['name' => 'Vendedor de prueba', 'guard_name' => 'api']);
        Permission::create(['name' => 'rol_permiso', 'guard_name' => 'api']);
        Permission::create(['name' => 'directo_permiso', 'guard_name' => 'api']);
        $role->givePermissionTo('rol_permiso');

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->autenticarActorConPermiso('directo_permiso');

        $response = app(UserController::class)->permisosDirectos(
            new Request(['permissions' => ['directo_permiso']]),
            (string) $user->id
        );
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['directo_permiso'], $body['direct_permissions']);

        $user->refresh();
        // El rol conserva su permiso propio, sin que el directo lo pise.
        $this->assertTrue($role->fresh()->hasPermissionTo('rol_permiso'));
        $this->assertFalse($role->fresh()->hasPermissionTo('directo_permiso'));
        // El usuario ve AMBOS (getAllPermissions = rol + directos).
        $this->assertTrue($user->getAllPermissions()->pluck('name')->contains('rol_permiso'));
        $this->assertTrue($user->getAllPermissions()->pluck('name')->contains('directo_permiso'));
    }

    public function test_permisos_directos_con_lista_vacia_los_revoca_todos(): void
    {
        Permission::create(['name' => 'directo_permiso', 'guard_name' => 'api']);
        $user = User::factory()->create();
        $user->givePermissionTo('directo_permiso');

        // Revocar (lista vacía) no agrega nada nuevo — el guard anti-
        // escalación no debe exigir que el actor tenga 'directo_permiso'
        // para poder quitárselo a otro.
        $this->autenticarActorConPermiso(null);

        $response = app(UserController::class)->permisosDirectos(
            new Request(['permissions' => []]),
            (string) $user->id
        );
        $body = $response->getData(true);

        $this->assertSame([], $body['direct_permissions']);
        $this->assertCount(0, $user->fresh()->getDirectPermissions());
    }

    // Hallazgo real de revisión posterior (no parte del brief original de
    // Fase 2d): el endpoint solo exigía 'edit_user' en la ruta, mucho más
    // común que 'edit_role'/'delete_role' — sin este guard, cualquier rol
    // con edit_user podía otorgarse (o darle a otro) CUALQUIER permiso
    // existente, incluidos delete_role/roles.administrar, sin tenerlo él
    // mismo. El guard compara el DELTA contra los permisos reales del
    // actor (vía can(), que sí respeta el bypass de Super-Admin por
    // Gate::before() — getAllPermissions() no lo haría, Super-Admin no
    // tiene permisos explícitos asignados).
    public function test_no_permite_otorgar_un_permiso_que_el_actor_no_tiene(): void
    {
        Permission::create(['name' => 'delete_role', 'guard_name' => 'api']);
        $objetivo = User::factory()->create();

        $this->autenticarActorConPermiso(null); // actor sin ningún permiso

        $response = app(UserController::class)->permisosDirectos(
            new Request(['permissions' => ['delete_role']]),
            (string) $objetivo->id
        );
        $body = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('delete_role', $body['message']);
        $this->assertCount(0, $objetivo->fresh()->getDirectPermissions());
    }

    public function test_conservar_un_permiso_existente_no_exige_que_el_actor_lo_tenga(): void
    {
        Permission::create(['name' => 'permiso_viejo', 'guard_name' => 'api']);
        Permission::create(['name' => 'permiso_nuevo', 'guard_name' => 'api']);
        $objetivo = User::factory()->create();
        $objetivo->givePermissionTo('permiso_viejo'); // otorgado antes, por otro actor (ej. Super-Admin)

        // El actor de este request solo tiene 'permiso_nuevo' — no
        // 'permiso_viejo' — pero el request incluye ambos (checklist:
        // conserva lo existente + agrega lo nuevo). No debe rechazarse,
        // porque 'permiso_viejo' no es parte del delta.
        $this->autenticarActorConPermiso('permiso_nuevo');

        $response = app(UserController::class)->permisosDirectos(
            new Request(['permissions' => ['permiso_viejo', 'permiso_nuevo']]),
            (string) $objetivo->id
        );
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEqualsCanonicalizing(['permiso_viejo', 'permiso_nuevo'], $body['direct_permissions']);
    }

    public function test_super_admin_puede_otorgar_cualquier_permiso_pese_a_no_tener_ninguno_explicito(): void
    {
        Permission::create(['name' => 'roles.administrar', 'guard_name' => 'api']);
        $objetivo = User::factory()->create();

        $role = Role::create(['name' => 'Super-Admin', 'guard_name' => 'api']);
        $actor = User::factory()->create();
        $actor->assignRole($role);
        Auth::guard('api')->setUser($actor->fresh());

        $response = app(UserController::class)->permisosDirectos(
            new Request(['permissions' => ['roles.administrar']]),
            (string) $objetivo->id
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    private function autenticarActorConPermiso(?string $permiso): void
    {
        $actor = User::factory()->create();
        if ($permiso) {
            $actor->givePermissionTo($permiso);
        }
        Auth::guard('api')->setUser($actor->fresh());
    }

    public function test_index_devuelve_permisos_disponibles(): void
    {
        Permission::create(['name' => 'cotizaciones.ver_todas', 'guard_name' => 'api']);

        $response = app(UserController::class)->index(new Request());
        $body = $response->getData(true);

        $this->assertArrayHasKey('permisos_disponibles', $body);
        $this->assertContains('cotizaciones.ver_todas', $body['permisos_disponibles']);
    }

    public function test_user_resource_expone_direct_permissions(): void
    {
        Permission::create(['name' => 'directo_permiso', 'guard_name' => 'api']);
        $user = User::factory()->create();
        $user->givePermissionTo('directo_permiso');

        $array = UserResource::make($user->fresh())->toArray(new Request());

        $this->assertArrayHasKey('direct_permissions', $array);
        $this->assertContains('directo_permiso', $array['direct_permissions']->all());
    }
}
