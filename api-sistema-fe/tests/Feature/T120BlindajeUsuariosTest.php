<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\User\UserController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// T-120 C — un usuario sin rol no puede quedar como Super-Admin (role_id con default 1).
class T120BlindajeUsuariosTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function crear(array $datos)
    {
        return app(UserController::class)->store(new Request($datos + [
            'name' => 'Prueba', 'surname' => 'T120', 'email' => 'u' . uniqid() . '@t120.test', 'password' => 'secreto123',
        ]));
    }

    public function test_store_exige_role_id(): void
    {
        $email = 'sinrol' . uniqid() . '@t120.test';
        try {
            $this->crear(['email' => $email]);
            $this->fail('Se esperaba un 422 por falta de role_id.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role_id', $e->errors());
        }
        $this->assertFalse(User::where('email', $email)->exists());

        $this->expectException(ValidationException::class);
        $this->crear(['role_id' => 999999]);
    }

    public function test_store_con_rol_valido_crea_el_usuario(): void
    {
        $rol = Role::create(['name' => 'rol-t120-' . uniqid(), 'guard_name' => 'api']);
        $respuesta = $this->crear(['role_id' => $rol->id])->getData(true);

        $this->assertSame(200, $respuesta['code']);
        $this->assertSame($rol->id, $respuesta['user']['role']['id']);
    }

    public function test_usuario_sin_rol_no_es_super_admin(): void
    {
        $this->assertNull(
            DB::selectOne("SELECT column_default FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'role_id'")->column_default
        );

        $user = User::factory()->create(['role_id' => null]);
        $this->assertNull($user->fresh()->role_id);

        Auth::guard('api')->setUser($user->fresh());
        $metodo = new ReflectionMethod(AuthController::class, 'respondWithToken');
        $metodo->setAccessible(true);
        $data = $metodo->invoke(app(AuthController::class), 'token-de-prueba')->getData(true);

        $this->assertNull($data['user']['role']['name']);
        $this->assertSame([], $data['user']['permissions']);
    }
}
