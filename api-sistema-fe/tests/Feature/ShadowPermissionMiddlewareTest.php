<?php

namespace Tests\Feature;

use App\Http\Middleware\ShadowPermissionMiddleware;
use App\Models\PermissionShadowLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Fase 0c (plan-modulo-menus-y-roles.md §9.1, paso 2 — modo "sombra", Bucket B:
// 31 rutas operativas). Mismo criterio de doble capa que GateBucketARoutesTest
// (Fase 0b), más una tercera capa específica del modo sombra: confirmar que
// NUNCA cambia el comportamiento observable de la ruta (crítico — un bug acá
// que bloqueara por accidente sería exactamente el incidente que el modo
// sombra existe para evitar).
//
// 1. RUTA_PERMISO: la ruta REAL registrada exige el shadow.permission:X
//    esperado.
// 2. test_shadow_middleware_nunca_bloquea_...: con y sin el permiso, el
//    resultado de $next() pasa sin cambios (mismo valor, sin excepción).
// 3. test_shadow_middleware_registra_..._y_no_registra_...: el log se crea
//    solo cuando falta el permiso, con los campos esperados.
// 4. test_super_admin_nunca_genera_log: Super-Admin bypasea cualquier gate
//    real vía Gate::before() — lo mismo debe reflejar el log, o el modo
//    sombra reportaría bloqueos que nunca van a ocurrir de verdad.
class ShadowPermissionMiddlewareTest extends TestCase
{
    private const RUTA_PERMISO = [
        ['POST', 'api/categories/{id}', 'edit_categorie'],
        ['POST', 'api/categories', 'register_categorie'],
        ['PUT', 'api/categories/{category}', 'edit_categorie'],
        ['DELETE', 'api/categories/{category}', 'delete_categorie'],

        ['POST', 'api/products/{id}', 'edit_product'],
        ['POST', 'api/products', 'register_product'],
        ['PUT', 'api/products/{product}', 'edit_product'],
        ['DELETE', 'api/products/{product}', 'delete_product'],

        ['POST', 'api/clients', 'register_client'],
        ['PUT', 'api/clients/{client}', 'edit_client'],
        ['DELETE', 'api/clients/{client}', 'delete_client'],

        ['POST', 'api/clients/{client}/payments/preview', 'registrar-pago-credito'],
        ['POST', 'api/clients/{client}/payments', 'registrar-pago-credito'],

        ['POST', 'api/sales/index', 'list_sale'],
        ['POST', 'api/sales', 'register_sale'],
        ['PUT', 'api/sales/{sale}', 'edit_sale'],
        ['DELETE', 'api/sales/{sale}', 'delete_sale'],

        ['POST', 'api/sale_details', 'register_sale_detail'],
        ['PUT', 'api/sale_details/{sale_detail}', 'edit_sale_detail'],
        ['DELETE', 'api/sale_details/{sale_detail}', 'delete_sale_detail'],

        ['POST', 'api/sale_payments', 'register_sale_payment'],
        ['PUT', 'api/sale_payments/{sale_payment}', 'edit_sale_payment'],
        ['DELETE', 'api/sale_payments/{sale_payment}', 'delete_sale_payment'],

        ['POST', 'api/enviarSunat', 'enviar_sunat'],

        ['POST', 'api/installments/schedule-preview', 'register_sale'],
        ['PATCH', 'api/installments/{installment}', 'editar-cuota-credito'],

        ['POST', 'api/sales/{sale}/installments/preview', 'registrar-cronograma-credito'],
        ['POST', 'api/sales/{sale}/installments', 'registrar-cronograma-credito'],

        ['POST', 'api/notas', 'nota_electronica'],
        ['POST', 'api/notas/preview', 'nota_electronica'],
        ['POST', 'api/notas/enviar-sunat', 'nota_electronica'],
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
    public function test_la_ruta_registrada_exige_el_shadow_permiso_esperado(string $method, string $uri, string $permisoEsperado): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true)
        );

        $this->assertNotNull($route, "No se encontró la ruta {$method} {$uri} en el router.");

        $middlewarePermisos = collect($route->gatherMiddleware())
            ->filter(fn ($m) => str_starts_with($m, 'shadow.permission:'))
            ->map(fn ($m) => substr($m, strlen('shadow.permission:')))
            ->values()
            ->all();

        $this->assertContains(
            $permisoEsperado,
            $middlewarePermisos,
            "{$method} {$uri} no lleva shadow.permission:{$permisoEsperado} — middleware real: " . implode(',', $middlewarePermisos)
        );

        // Bucket B nunca lleva el gate real de Spatie en esta fase — si esto
        // falla, alguien mezcló modo sombra con gate real en la misma ruta.
        $gateReal = collect($route->gatherMiddleware())
            ->filter(fn ($m) => str_starts_with($m, 'permission:'))
            ->values()
            ->all();
        $this->assertEmpty($gateReal, "{$method} {$uri} ya lleva permission: real — Bucket B debe quedar solo en modo sombra hasta la Parte 3.");
    }

    #[DataProvider('rutaPermisoProvider')]
    public function test_shadow_middleware_nunca_bloquea_con_o_sin_permiso(string $method, string $uri, string $permiso): void
    {
        $middleware = new ShadowPermissionMiddleware();
        $next = fn ($request) => 'ok-sin-cambios';

        $sinPermiso = User::factory()->create();
        Auth::guard('api')->setUser($sinPermiso->fresh());
        $resultado = $middleware->handle(Request::create('/test'), $next, $permiso);
        $this->assertSame('ok-sin-cambios', $resultado, "Sin '{$permiso}' el modo sombra NO debe cambiar el resultado de {$method} {$uri}.");

        $conPermiso = User::factory()->create();
        Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']);
        $conPermiso->givePermissionTo($permiso);
        Auth::guard('api')->setUser($conPermiso->fresh());
        $resultado = $middleware->handle(Request::create('/test'), $next, $permiso);
        $this->assertSame('ok-sin-cambios', $resultado, "Con '{$permiso}' el resultado de {$method} {$uri} debe seguir siendo el mismo.");
    }

    public function test_shadow_middleware_registra_log_solo_cuando_falta_el_permiso(): void
    {
        $middleware = new ShadowPermissionMiddleware();
        $next = fn ($request) => 'ok';
        $permiso = 'register_sale';

        $sinPermiso = User::factory()->create(['email' => 'cajero@test.local']);
        Auth::guard('api')->setUser($sinPermiso->fresh());

        $request = Request::create('/api/sales', 'POST');
        // Route real mínima (no se pasa por el router completo — mismo
        // criterio que GateBucketARoutesTest: no depender del stack de
        // tenancy en tests) solo para que optional($request->route())->uri()
        // resuelva a algo real dentro del middleware.
        $request->setRouteResolver(fn () => new \Illuminate\Routing\Route('POST', 'api/sales', []));

        $middleware->handle($request, $next, $permiso);

        $this->assertDatabaseHas('permission_shadow_logs', [
            'user_id' => $sinPermiso->id,
            'user_email' => 'cajero@test.local',
            'metodo_http' => 'POST',
            'permiso_faltante' => $permiso,
        ]);

        $conteoAntes = PermissionShadowLog::count();

        $conPermiso = User::factory()->create();
        Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']);
        $conPermiso->givePermissionTo($permiso);
        Auth::guard('api')->setUser($conPermiso->fresh());

        $middleware->handle($request, $next, $permiso);

        $this->assertSame($conteoAntes, PermissionShadowLog::count(), 'Un usuario CON el permiso no debe generar ningún log nuevo.');
    }

    public function test_super_admin_nunca_genera_log_aunque_le_falte_el_permiso(): void
    {
        $role = Role::create(['name' => 'Super-Admin', 'guard_name' => 'api']);
        $user = User::factory()->create();
        $user->assignRole($role);
        Auth::guard('api')->setUser($user->fresh());

        $middleware = new ShadowPermissionMiddleware();
        $next = fn ($request) => 'ok';

        $conteoAntes = PermissionShadowLog::count();
        $middleware->handle(Request::create('/api/roles', 'POST'), $next, 'register_role');
        $this->assertSame($conteoAntes, PermissionShadowLog::count(), 'Super-Admin bypasea todo gate real — no debe generar ruido en el log de modo sombra.');
    }
}
