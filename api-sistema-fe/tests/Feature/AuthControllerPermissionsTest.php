<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Fase 0 (plan-modulo-menus-y-roles, fix de permisos legacy) — reproduce el bug
// confirmado en Módulo Caja Fase 5 (19-jul-2026): AuthController::respondWithToken()
// armaba 'permissions' leyendo auth('api')->user()->role->permissions (relación
// legacy hacia role_id, solo trae los permisos del ROL) en vez de
// getAllPermissions() (rol + permisos asignados directo al usuario). Un permiso
// directo (ej. cash.approve_expenses en un cajero real de prueba) nunca llegaba al
// array que consume el frontend (isPermitedRoute()) aunque el middleware
// permission: del backend sí lo respetaba siempre.
//
// Corre contra sistemafe_test_migrations (Postgres real), mismo patrón que
// SaleControllerSerieComprobanteTest: Auth::guard('api')->setUser() en vez de un
// login HTTP real (el proyecto no tiene tests de ruta HTTP — ninguno usa
// postJson/actingAs; la resolución de tenant por subdominio haría un test así mucho
// más caro sin agregar cobertura real sobre el bug) + ReflectionMethod para invocar
// el método protected directamente.
class AuthControllerPermissionsTest extends TestCase
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
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // users.role_id tiene default(1) a nivel de Postgres (users_role_id_foreign) —
        // mismo fixture que SaleControllerSerieComprobanteTest/ReservarCorrelativoTest,
        // sin esto cualquier User::factory() revienta con FK violation.
        DB::table('roles')->insert([
            'id' => 1,
            'name' => 'test-role-default',
            'guard_name' => 'api',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function respondWithToken(string $token): array
    {
        $controller = app(AuthController::class);
        $method = new ReflectionMethod(AuthController::class, 'respondWithToken');
        $method->setAccessible(true);

        return $method->invoke($controller, $token)->getData(true);
    }

    public function test_permiso_asignado_directo_al_usuario_llega_en_el_login(): void
    {
        $role = Role::create(['name' => 'rol-cajero-' . uniqid(), 'guard_name' => 'api']);
        $permisoDelRol = Permission::firstOrCreate(['name' => 'cash.open_session', 'guard_name' => 'api']);
        $role->givePermissionTo($permisoDelRol);

        $permisoDirecto = Permission::firstOrCreate(['name' => 'cash.approve_expenses', 'guard_name' => 'api']);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->givePermissionTo($permisoDirecto); // NUNCA vía rol — directo al usuario
        $user->role_id = $role->id;
        $user->save();

        Auth::guard('api')->setUser($user->fresh());

        $data = $this->respondWithToken('token-de-prueba');
        $permissions = $data['user']['permissions'];

        $this->assertContains('cash.open_session', $permissions);
        $this->assertContains(
            'cash.approve_expenses',
            $permissions,
            'El permiso asignado directo al usuario (sin pasar por el rol) debe llegar en el login.'
        );
    }

    public function test_usuario_sin_permisos_directos_solo_trae_los_del_rol(): void
    {
        $role = Role::create(['name' => 'rol-basico-' . uniqid(), 'guard_name' => 'api']);
        $permisoDelRol = Permission::firstOrCreate(['name' => 'ver_dashboard', 'guard_name' => 'api']);
        $role->givePermissionTo($permisoDelRol);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->role_id = $role->id;
        $user->save();

        Auth::guard('api')->setUser($user->fresh());

        $data = $this->respondWithToken('token-de-prueba');
        $permissions = $data['user']['permissions'];

        $this->assertSame(['ver_dashboard'], $permissions);
    }
}
