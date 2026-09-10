<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Fase 0b (plan-modulo-menus-y-roles.md §9.1, Bucket A) — verifica el gate
// nuevo de las 38 rutas administrativas que antes solo tenían auth:api.
//
// Dos capas de prueba, no una sola genérica (según pide el brief):
//
// 1. RUTA_PERMISO (abajo): que la ruta REAL registrada en routes/api.php
//    exige exactamente el permiso esperado — falla si alguien borra o
//    cambia un ->middlewareFor() sin querer. Cubre las 38 rutas una por una
//    (algunas comparten permiso, ej. las 2 rutas de update de "users" — se
//    prueban igual las 2, no se deduplican).
// 2. test_permission_middleware_bloquea_sin_permiso_y_deja_pasar_con_permiso
//    (data provider RUTA_PERMISO reusado): invoca el middleware real de
//    Spatie (no una réplica) con un usuario sin el permiso → 403 real
//    (UnauthorizedException); con el permiso → deja pasar. No se hace vía
//    HTTP real (postJson) porque ninguna ruta de este proyecto pasa por el
//    stack completo tenant/tenant.active/tenant.subscription/tenant.token
//    en tests (ninguna lo hace hoy en toda la suite) — se invoca
//    PermissionMiddleware::handle() directo, que es la misma clase real que
//    corre en producción, solo sin el resto del stack de tenancy.
//
// Corre contra sistemafe_test_migrations (Postgres real), mismo patrón que
// SaleControllerSerieComprobanteTest/AuthControllerPermissionsTest.
class GateBucketARoutesTest extends TestCase
{
    private const RUTA_PERMISO = [
        ['POST', 'api/roles', 'register_role'],
        ['PUT', 'api/roles/{role}', 'edit_role'],
        ['DELETE', 'api/roles/{role}', 'delete_role'],
        ['POST', 'api/users', 'register_user'],
        ['POST', 'api/users/{id}', 'edit_user'],
        ['PUT', 'api/users/{user}', 'edit_user'],
        ['DELETE', 'api/users/{user}', 'delete_user'],
        ['POST', 'api/company', 'company'],
        ['PUT', 'api/company/{company}', 'company'],
        ['DELETE', 'api/company/{company}', 'company'],
        ['POST', 'api/branches', 'register_branch'],
        ['PUT', 'api/branches/{branch}', 'edit_branch'],
        ['DELETE', 'api/branches/{branch}', 'delete_branch'],
        ['POST', 'api/cash-registers', 'register_cash_register'],
        ['PUT', 'api/cash-registers/{cash_register}', 'edit_cash_register'],
        ['DELETE', 'api/cash-registers/{cash_register}', 'delete_cash_register'],
        ['POST', 'api/payment-methods', 'register_payment_method'],
        ['PUT', 'api/payment-methods/{payment_method}', 'edit_payment_method'],
        ['DELETE', 'api/payment-methods/{payment_method}', 'delete_payment_method'],
        ['POST', 'api/suppliers', 'register_supplier'],
        ['PUT', 'api/suppliers/{supplier}', 'edit_supplier'],
        ['DELETE', 'api/suppliers/{supplier}', 'delete_supplier'],
        ['POST', 'api/cash-concepts', 'register_cash_concept'],
        ['PUT', 'api/cash-concepts/{cash_concept}', 'edit_cash_concept'],
        ['DELETE', 'api/cash-concepts/{cash_concept}', 'delete_cash_concept'],
        ['POST', 'api/series-comprobante', 'register_serie_comprobante'],
        ['PUT', 'api/series-comprobante/{series_comprobante}', 'edit_serie_comprobante'],
        ['DELETE', 'api/series-comprobante/{series_comprobante}', 'delete_serie_comprobante'],
        ['POST', 'api/systems', 'register_system'],
        ['PUT', 'api/systems/{system}', 'edit_system'],
        ['DELETE', 'api/systems/{system}', 'delete_system'],
        ['POST', 'api/system_categories', 'register_categorie_system'],
        ['POST', 'api/system_categories/{id}', 'edit_categorie_system'],
        ['PUT', 'api/system_categories/{system_category}', 'edit_categorie_system'],
        ['DELETE', 'api/system_categories/{system_category}', 'delete_categorie_system'],
        ['POST', 'api/recursos', 'register_recurso'],
        ['PUT', 'api/recursos/{recurso}', 'edit_recurso'],
        ['DELETE', 'api/recursos/{recurso}', 'delete_recurso'],
    ];

    public static function rutaPermisoProvider(): array
    {
        $out = [];
        foreach (self::RUTA_PERMISO as [$method, $uri, $permiso]) {
            $out["$method $uri -> $permiso"] = [$method, $uri, $permiso];
        }

        return $out;
    }

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

    #[DataProvider('rutaPermisoProvider')]
    public function test_la_ruta_registrada_exige_el_permiso_esperado(string $method, string $uri, string $permisoEsperado): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true)
        );

        $this->assertNotNull($route, "No se encontró la ruta {$method} {$uri} en el router.");

        $middlewarePermisos = collect($route->gatherMiddleware())
            ->filter(fn ($m) => str_starts_with($m, 'permission:'))
            ->map(fn ($m) => substr($m, strlen('permission:')))
            ->values()
            ->all();

        $this->assertContains(
            $permisoEsperado,
            $middlewarePermisos,
            "{$method} {$uri} no exige '{$permisoEsperado}' — middleware real: " . implode(',', $middlewarePermisos)
        );
    }

    #[DataProvider('rutaPermisoProvider')]
    public function test_permission_middleware_bloquea_sin_permiso_y_deja_pasar_con_permiso(string $method, string $uri, string $permiso): void
    {
        $middleware = new PermissionMiddleware();
        $next = fn ($request) => 'ok';

        $sinPermiso = User::factory()->create();
        Auth::guard('api')->setUser($sinPermiso->fresh());

        try {
            $middleware->handle(new Request(), $next, $permiso);
            $this->fail("Se esperaba UnauthorizedException (403) para un usuario sin '{$permiso}' en {$method} {$uri}.");
        } catch (UnauthorizedException $e) {
            $this->assertTrue(true);
        }

        $conPermiso = User::factory()->create();
        Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']);
        $conPermiso->givePermissionTo($permiso);
        Auth::guard('api')->setUser($conPermiso->fresh());

        $resultado = $middleware->handle(new Request(), $next, $permiso);
        $this->assertSame('ok', $resultado, "Un usuario CON '{$permiso}' debería pasar el gate de {$method} {$uri}.");
    }

    public function test_super_admin_bypasea_el_gate_via_gate_before(): void
    {
        $role = \Spatie\Permission\Models\Role::create(['name' => 'Super-Admin', 'guard_name' => 'api']);
        $user = User::factory()->create();
        $user->assignRole($role);
        Auth::guard('api')->setUser($user->fresh());

        $middleware = new PermissionMiddleware();
        $next = fn ($request) => 'ok';

        // Super-Admin no tiene 'register_role' asignado (ni ningún otro permiso) —
        // pasa igual, vía Gate::before() en AppServiceProvider.
        $resultado = $middleware->handle(new Request(), $next, 'register_role');
        $this->assertSame('ok', $resultado);
    }
}
