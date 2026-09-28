<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GateBucketBPermisos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Fase 0c, Parte 3 (plan-modulo-menus-y-roles.md §9.1, Bucket B: 31 rutas
// operativas) — gate REAL, reemplaza a ShadowPermissionMiddlewareTest (el modo
// sombra que solo registraba). Mismo criterio de doble capa que
// GateBucketARoutesTest:
//
// 1. RUTA_PERMISO: la ruta REAL registrada exige permission:X — y ya no lleva
//    shadow.permission: (falla si alguien deja la ruta a medio migrar).
// 2. El PermissionMiddleware real de Spatie bloquea sin el permiso y deja pasar
//    con él.
// 3. GateBucketBPermisos (backfill previo a activar el gate): crea los permisos
//    nuevos y los deriva a roles/usuarios que ya usan esos flujos, sin quitar
//    nada ni tocar a quien no corresponde.
class GateBucketBRoutesTest extends TestCase
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

        // Vista previa del cronograma: la usan register.vue Y edit.vue, por eso
        // basta con cualquiera de los dos.
        ['POST', 'api/installments/schedule-preview', 'register_sale|edit_sale'],
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
    public function test_la_ruta_registrada_exige_el_permiso_real(string $method, string $uri, string $permisoEsperado): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true)
        );

        $this->assertNotNull($route, "No se encontró la ruta {$method} {$uri} en el router.");

        $middleware = collect($route->gatherMiddleware());

        $permisos = $middleware
            ->filter(fn ($m) => str_starts_with($m, 'permission:'))
            ->map(fn ($m) => substr($m, strlen('permission:')))
            ->values()
            ->all();

        $this->assertContains(
            $permisoEsperado,
            $permisos,
            "{$method} {$uri} no exige permission:{$permisoEsperado} — middleware real: " . implode(',', $permisos)
        );

        $this->assertEmpty(
            $middleware->filter(fn ($m) => str_starts_with($m, 'shadow.permission:'))->all(),
            "{$method} {$uri} todavía lleva shadow.permission: — Bucket B ya es gate real."
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

        // Con uno solo de los permisos (si la ruta acepta varios con "|") ya pasa.
        $unPermiso = explode('|', $permiso)[0];
        $conPermiso = User::factory()->create();
        Permission::firstOrCreate(['name' => $unPermiso, 'guard_name' => 'api']);
        $conPermiso->givePermissionTo($unPermiso);
        Auth::guard('api')->setUser($conPermiso->fresh());

        $resultado = $middleware->handle(new Request(), $next, $permiso);
        $this->assertSame('ok', $resultado, "Un usuario CON '{$unPermiso}' debería pasar el gate de {$method} {$uri}.");
    }

    public function test_el_403_responde_en_espanol_con_el_permiso_requerido(): void
    {
        $sinPermiso = User::factory()->create();
        Auth::guard('api')->setUser($sinPermiso->fresh());

        $request = Request::create('/api/enviarSunat', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $e = UnauthorizedException::forPermissions(['enviar_sunat']);
        $response = app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render($request, $e);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('No tienes permiso', $data['message']);
        $this->assertStringContainsString('enviar_sunat', $data['message']);
        $this->assertSame(['enviar_sunat'], $data['permisos_requeridos']);
    }

    public function test_backfill_deriva_permisos_a_quien_ya_usa_el_flujo_sin_quitar_nada(): void
    {
        foreach (['register_sale', 'edit_sale', 'list_sale', 'register_product', 'cotizaciones.crear', 'dashboard', 'anular-pago-credito'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }

        $cajero = Role::create(['name' => 'Cajero', 'guard_name' => 'api']);
        $cajero->givePermissionTo(['register_sale', 'edit_sale', 'list_sale', 'dashboard']);

        $almacen = Role::create(['name' => 'Jefe de Almacen', 'guard_name' => 'api']);
        $almacen->givePermissionTo(['register_product']);

        $vendedorAgencia = Role::create(['name' => 'Vendedor de agencia', 'guard_name' => 'api']);
        $vendedorAgencia->givePermissionTo(['cotizaciones.crear']);

        $superAdmin = Role::create(['name' => 'Super-Admin', 'guard_name' => 'api']);

        // Usuario con permiso directo (Fase 2d), sin rol que lo cubra.
        $cobrador = User::factory()->create();
        $cobrador->givePermissionTo('anular-pago-credito');

        $resultado = app(GateBucketBPermisos::class)->aplicar();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (GateBucketBPermisos::PERMISOS_NUEVOS as $nuevo) {
            $this->assertTrue(Permission::where('name', $nuevo)->exists(), "Falta crear {$nuevo}.");
        }

        $cajero = $cajero->fresh();
        foreach (['enviar_sunat', 'register_client', 'edit_client', 'registrar-cronograma-credito', 'editar-cuota-credito', 'registrar-pago-credito', 'register_sale_detail', 'edit_sale_payment'] as $p) {
            $this->assertTrue($cajero->hasPermissionTo($p), "El Cajero debería recibir {$p}.");
        }
        // No deriva lo que no corresponde: eliminar clientes, productos, borrar detalle sin delete_sale.
        foreach (['delete_client', 'register_product', 'delete_sale_detail', 'nota_electronica'] as $p) {
            $this->assertFalse($cajero->permissions->contains('name', $p), "El Cajero NO debería recibir {$p}.");
        }
        // Nunca quita lo que ya tenía.
        $this->assertTrue($cajero->hasPermissionTo('dashboard'));

        $this->assertSame(['register_product'], $almacen->fresh()->permissions->pluck('name')->all(), 'Jefe de Almacen no usa ningún flujo derivado.');

        $vendedorAgencia = $vendedorAgencia->fresh();
        $this->assertTrue($vendedorAgencia->hasPermissionTo('register_client'), 'El cotizador crea clientes al vuelo.');
        $this->assertFalse($vendedorAgencia->permissions->contains('name', 'enviar_sunat'));

        $this->assertCount(0, $superAdmin->fresh()->permissions, 'Super-Admin bypasea vía Gate::before, no se le asigna nada.');

        $this->assertTrue($cobrador->fresh()->hasDirectPermission('registrar-pago-credito'));

        // Idempotente: una segunda corrida no crea ni otorga nada.
        $segunda = app(GateBucketBPermisos::class)->aplicar();
        $this->assertSame([], $segunda['creados']);
        $this->assertSame([], $segunda['otorgados']);
        $this->assertNotEmpty($resultado['otorgados']);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        Permission::firstOrCreate(['name' => 'register_sale', 'guard_name' => 'api']);
        $cajero = Role::create(['name' => 'Cajero', 'guard_name' => 'api']);
        $cajero->givePermissionTo('register_sale');

        $resultado = app(GateBucketBPermisos::class)->aplicar(dryRun: true);

        $this->assertContains('enviar_sunat', $resultado['creados']);
        $this->assertNotEmpty($resultado['otorgados']);
        $this->assertFalse(Permission::where('name', 'enviar_sunat')->exists());
        $this->assertSame(['register_sale'], $cajero->fresh()->permissions->pluck('name')->all());
    }
}
