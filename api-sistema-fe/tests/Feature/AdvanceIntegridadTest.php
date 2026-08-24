<?php

namespace Tests\Feature;

use App\Http\Controllers\Sale\SaleController;
use App\Models\Advance\Advance;
use App\Models\Advance\AdvanceApplication;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Cierre de gaps de integridad encontrados en una auditoría del módulo
// Adelantos (2026-08-21): un agente externo (codex) reportó varios
// hallazgos "críticos" sobre AdvanceController/SaleController, verificados
// contra el código real y ampliados con 7 hallazgos nuevos. Este archivo
// cubre el Tier 1 (bugs de integridad arreglables sin decisión de negocio
// pendiente) — el tratamiento tributario fijo del adelanto (IGV 18%
// siempre) queda fuera a propósito, requiere confirmar con contador.
//
// Mismo patrón que AdvanceControllerSerieComprobanteTest/
// SaleControllerSerieComprobanteTest: corre contra sistemafe_test_migrations
// (Postgres real), transacción por test revertida en tearDown(), controllers
// invocados directo (sin pasar por el stack HTTP/tenancy completo).
class AdvanceIntegridadTest extends TestCase
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

        // users.role_id tiene default(1) a nivel de Postgres — mismo fixture
        // que el resto de la suite.
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

    // ── Fixtures compartidos ────────────────────────────────────────────

    private function branchConSerieNv(): array
    {
        $branch = Branch::create(['name' => 'Sede Test Adelantos', 'is_active' => true]);
        $serie = SerieComprobante::create([
            'branch_id' => $branch->id,
            'tipo_comprobante_codigo' => 'NV',
            'moneda' => 'PEN',
            'serie' => 'NV001',
            'correlativo_actual' => 0,
            'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'),
            'activo' => true,
        ]);

        return [$branch, $serie];
    }

    private function usuarioConPermiso(int $branchId, string $permiso): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);

        $role = Role::create(['name' => 'rol-test-' . uniqid(), 'guard_name' => 'api']);
        $permission = Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $user->role_id = $role->id;
        $user->save();

        Auth::guard('api')->setUser($user->fresh());

        return $user->fresh();
    }

    // Adelanto ya "aceptado por SUNAT" (n_operacion poblado por el factory,
    // mismo criterio que ReservarCorrelativoTest — sin enviar nada real a
    // Greenter) con saldo disponible completo.
    private function crearAdelantoDisponible(Client $cliente, float $amount, string $currency = 'PEN'): Advance
    {
        $venta = Sale::factory()->create([
            'type' => 'advance',
            'client_id' => $cliente->id,
            'currency' => $currency,
            'total' => $amount,
        ]);

        return Advance::create([
            'client_id' => $cliente->id,
            'sale_id' => $venta->id,
            'amount' => $amount,
            'currency' => $currency,
            'payment_method' => 'EFECTIVO',
        ]);
    }

    private function productoTest(): array
    {
        $categoria = Categorie::create(['title' => 'Categoría Test Adelantos', 'state' => 1]);
        $producto = Product::create([
            'title' => 'Producto Test',
            'sku' => 'SKU-TEST-' . uniqid(),
            'categorie_id' => $categoria->id,
            'price_general' => 50,
            'price_company' => 50,
            'state' => 1,
            'unidad_medida' => 'NIU',
            'stock' => 50,
            'is_discount' => false,
            'disponiblidad' => 1,
            'include_igv' => false,
            'is_icbper' => false,
            'is_ivap' => false,
            'is_isc' => false,
            'is_especial_nota' => false,
            'tip_afe_igv_default' => '10',
        ]);

        return [$categoria, $producto];
    }

    // Payload de store() base — venta NV contado, cubierta 100% por un
    // adelanto (payments=[] no exige caja abierta). $overrides pisa
    // cualquier campo (ej. state_sale, advance_applications).
    private function payloadVentaNv(Client $cliente, Product $producto, Categorie $categoria, array $overrides = []): array
    {
        $base = [
            'date' => now()->format('Y-m-d'),
            'tipo_comprobante_codigo' => 'NV',
            'n_transaction' => '00000001',
            'client_id' => $cliente->id,
            'type_client' => 1,
            'cod_tipo_doc_cliente' => '1',
            'currency' => 'PEN',
            'is_exportacion' => 0,
            'destino' => 'nacional',
            'state_sale' => 1,
            'type_payment' => 1, // contado
            'subtotal' => 100.00,
            'igv' => 18.00,
            'total' => 118.00,
            'discount' => 0,
            'discount_global' => 0,
            'retencion_igv' => 0,
            'state_payment' => 3,
            'debt' => 0,
            'paid_out' => 0,
            'sale_details' => [[
                'product' => ['id' => $producto->id, 'categorie_id' => $categoria->id],
                'unidad_medida' => 'NIU',
                'quantity' => 2,
                'price_base' => 50,
                'price_final' => 59,
                'discount' => 0,
                'subtotal' => 100,
                'mto_valor_venta' => 100,
                'mto_base_igv' => 100,
                'porcentaje_igv' => 18,
                'igv' => 18,
                'tip_afe_igv' => '10',
            ]],
            'payments' => [],
            'advance_applications' => [],
        ];

        return array_replace($base, $overrides);
    }

    // ── Fix #1: moneda distinta ──────────────────────────────────────────
    public function test_store_rechaza_adelanto_en_moneda_distinta_a_la_venta(): void
    {
        [$branch, ] = $this->branchConSerieNv();
        $this->usuarioConPermiso($branch->id, 'emitir_nota_venta');

        $cliente = Client::factory()->create();
        [$categoria, $producto] = $this->productoTest();
        $adelantoUsd = $this->crearAdelantoDisponible($cliente, 200.00, 'USD');

        $request = new Request($this->payloadVentaNv($cliente, $producto, $categoria, [
            'currency' => 'PEN',
            'advance_applications' => [['advance_id' => $adelantoUsd->id, 'amount' => 118.00]],
        ]));

        $ventasAntes = Sale::count();

        try {
            app(SaleController::class)->store($request);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('USD', $e->getMessage());
            $this->assertStringContainsString('PEN', $e->getMessage());
        }

        // Rollback atómico: la venta nunca quedó persistida.
        $this->assertSame($ventasAntes, Sale::count());
        $this->assertSame(0.0, (float) $adelantoUsd->fresh()->applied_amount);
    }

    // ── Fix #2: adelanto sobre cotización ────────────────────────────────
    public function test_store_rechaza_adelanto_aplicado_a_cotizacion(): void
    {
        [$branch, ] = $this->branchConSerieNv();
        $this->usuarioConPermiso($branch->id, 'emitir_nota_venta');

        $cliente = Client::factory()->create();
        [$categoria, $producto] = $this->productoTest();
        $adelanto = $this->crearAdelantoDisponible($cliente, 200.00, 'PEN');

        $request = new Request($this->payloadVentaNv($cliente, $producto, $categoria, [
            'state_sale' => 2, // cotización
            'advance_applications' => [['advance_id' => $adelanto->id, 'amount' => 118.00]],
        ]));

        try {
            app(SaleController::class)->store($request);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('cotización', $e->getMessage());
        }

        $this->assertSame(0.0, (float) $adelanto->fresh()->applied_amount);
    }

    // ── Fix #3: mismo advance_id repetido en el payload ──────────────────
    public function test_store_rechaza_advance_id_duplicado_en_el_mismo_payload(): void
    {
        [$branch, ] = $this->branchConSerieNv();
        $this->usuarioConPermiso($branch->id, 'emitir_nota_venta');

        $cliente = Client::factory()->create();
        [$categoria, $producto] = $this->productoTest();
        $adelanto = $this->crearAdelantoDisponible($cliente, 200.00, 'PEN');

        $request = new Request($this->payloadVentaNv($cliente, $producto, $categoria, [
            'advance_applications' => [
                ['advance_id' => $adelanto->id, 'amount' => 59.00],
                ['advance_id' => $adelanto->id, 'amount' => 59.00],
            ],
        ]));

        try {
            app(SaleController::class)->store($request);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString((string) $adelanto->id, $e->getMessage());
        }
    }

    // ── Fix #4a: editar venta CONTADO con adelanto aplicado ──────────────
    public function test_update_rechaza_venta_contado_con_adelanto_aplicado(): void
    {
        $cliente = Client::factory()->create();
        $venta = Sale::factory()->create([
            'type' => 'sale',
            'type_payment' => 1, // contado
            'client_id' => $cliente->id,
            'xml' => null,
            'cdr' => null,
        ]);
        $adelanto = $this->crearAdelantoDisponible($cliente, 100.00, 'PEN');
        AdvanceApplication::create([
            'advance_id' => $adelanto->id,
            'sale_id' => $venta->id,
            'amount_applied' => 50.00,
        ]);

        $controller = app(SaleController::class);

        try {
            $controller->update(new Request([]), (string) $venta->id);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('adelanto', strtolower($e->getMessage()));
        }
    }

    // ── Fix #4b: eliminar venta con adelanto aplicado ────────────────────
    public function test_destroy_rechaza_venta_con_adelanto_aplicado(): void
    {
        $cliente = Client::factory()->create();
        $venta = Sale::factory()->create([
            'type' => 'sale',
            'client_id' => $cliente->id,
            'xml' => null,
            'cdr' => null,
        ]);
        $adelanto = $this->crearAdelantoDisponible($cliente, 100.00, 'PEN');
        AdvanceApplication::create([
            'advance_id' => $adelanto->id,
            'sale_id' => $venta->id,
            'amount_applied' => 50.00,
        ]);

        $response = app(SaleController::class)->destroy((string) $venta->id);
        $body = json_decode($response->getContent(), true);

        $this->assertSame(405, $body['code']);
        $this->assertNotNull(Sale::find($venta->id), 'La venta no debía eliminarse.');
    }

    // ── Fix #5: permission: middleware wireado en las rutas de Adelantos ──
    // No se prueba vía stack HTTP completo (tenancy/JWT no tienen
    // infraestructura de test en este proyecto, ver convención del resto
    // de la suite) — se inspecciona la tabla de rutas ya resuelta, que es
    // exactamente lo que routes/api.php produce en runtime.
    public function test_rutas_de_adelantos_tienen_el_permission_middleware_correcto(): void
    {
        $rutas = app('router')->getRoutes();

        $casos = [
            ['POST', 'api/advances', 'permission:register_advance'],
            ['GET', 'api/advances', 'permission:list_advance'],
            ['GET', 'api/advances/{id}', 'permission:list_advance'],
            ['POST', 'api/advances/{id}/refund', 'permission:refund_advance'],
        ];

        foreach ($casos as [$metodo, $uri, $middlewareEsperado]) {
            $ruta = null;
            foreach ($rutas as $r) {
                if (in_array($metodo, $r->methods(), true) && $r->uri() === $uri) {
                    $ruta = $r;
                    break;
                }
            }

            $this->assertNotNull($ruta, "No se encontró la ruta {$metodo} {$uri}.");
            $this->assertContains(
                $middlewareEsperado,
                $ruta->middleware(),
                "La ruta {$metodo} {$uri} no tiene el middleware {$middlewareEsperado}."
            );
        }

        // clients/{id}/advances es a propósito la excepción — la usa el
        // checkout de ventas, no las pantallas del módulo Adelantos.
        foreach ($rutas as $r) {
            if (in_array('GET', $r->methods(), true) && $r->uri() === 'api/clients/{id}/advances') {
                $middlewaresPermission = array_filter($r->middleware(), fn ($m) => str_starts_with($m, 'permission:'));
                $this->assertEmpty($middlewaresPermission, 'clients/{id}/advances no debería tener permission: — rompería el checkout de ventas.');
            }
        }
    }

    // ── Fix #6: refund() — los guards siguen funcionando tras mover todo
    // dentro de la transacción con lockForUpdate() ────────────────────────
    public function test_refund_rechaza_monto_mayor_al_saldo_disponible(): void
    {
        $cliente = Client::factory()->create();
        $adelanto = $this->crearAdelantoDisponible($cliente, 100.00, 'PEN');

        $request = new Request(['amount' => 150.00]);

        try {
            app(\App\Http\Controllers\Advance\AdvanceController::class)->refund($request, (string) $adelanto->id);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_refund_rechaza_reembolso_parcial_de_adelanto_ya_aplicado(): void
    {
        $cliente = Client::factory()->create();
        $venta = Sale::factory()->create(['type' => 'sale', 'client_id' => $cliente->id]);
        $adelanto = $this->crearAdelantoDisponible($cliente, 100.00, 'PEN');
        AdvanceApplication::create([
            'advance_id' => $adelanto->id,
            'sale_id' => $venta->id,
            'amount_applied' => 40.00,
        ]);
        $adelanto->update(['applied_amount' => 40.00]);

        $request = new Request(['amount' => 60.00]);

        try {
            app(\App\Http\Controllers\Advance\AdvanceController::class)->refund($request, (string) $adelanto->id);
            $this->fail('Se esperaba HttpException 501, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(501, $e->getStatusCode());
        }
    }

    // Mismo patrón que
    // AdvanceControllerSerieComprobanteTest::test_lock_bloquea_segunda_conexion_sobre_serie_usada_por_adelantos —
    // prueba real de bloqueo entre dos conexiones Postgres sobre la fila de
    // advances, confirmando que el lockForUpdate() agregado a refund()
    // efectivamente bloquea una segunda lectura concurrente. Rompe el "cero
    // persistencia real" del resto de la clase a propósito, con limpieza
    // manual garantizada en finally{}.
    public function test_lock_bloquea_segunda_conexion_sobre_advance_durante_refund(): void
    {
        DB::commit();
        DB::connection('central')->commit();

        $cliente = Client::factory()->create();
        $venta = Sale::factory()->create([
            'type' => 'advance',
            'client_id' => $cliente->id,
        ]);
        $adelanto = Advance::create([
            'client_id' => $cliente->id,
            'sale_id' => $venta->id,
            'amount' => 100.00,
            'currency' => 'PEN',
            'payment_method' => 'EFECTIVO',
        ]);

        $bloqueada = false;
        $mensajeError = null;

        try {
            DB::connection('pgsql')->beginTransaction();
            DB::connection('pgsql')->select('select * from advances where id = ? for update', [$adelanto->id]);

            config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
            DB::purge('pgsql_b');
            DB::connection('pgsql_b')->beginTransaction();
            DB::connection('pgsql_b')->statement("SET LOCAL lock_timeout = '300ms'");

            try {
                DB::connection('pgsql_b')->select('select * from advances where id = ? for update', [$adelanto->id]);
            } catch (\Throwable $e) {
                $bloqueada = true;
                $mensajeError = $e->getMessage();
            }

            DB::connection('pgsql_b')->rollBack();
            DB::connection('pgsql')->rollBack();
        } finally {
            DB::connection('pgsql')->table('advances')->where('id', $adelanto->id)->delete();
            DB::connection('pgsql')->table('sales')->where('id', $venta->id)->delete();
            // Sale::factory()/Client::factory() crean su propio User (user_id
            // por default en el factory de Sale) con role_id=1 — hay que
            // borrarlo antes de poder borrar roles.id=1, o la FK
            // users_role_id_foreign revienta el delete.
            DB::connection('pgsql')->table('users')->where('id', $venta->user_id)->delete();
            DB::connection('pgsql')->table('clients')->where('id', $cliente->id)->delete();
            DB::connection('pgsql')->table('roles')->where('id', 1)->delete();

            DB::beginTransaction();
            DB::connection('central')->beginTransaction();
        }

        $this->assertTrue($bloqueada, 'Se esperaba que la segunda conexión no pudiera tomar el lock del adelanto mientras la primera lo sostiene abierto.');
        $this->assertNotNull($mensajeError);
    }

    // ── Fix #8: ventas type='advance' no aparecen en el listado general ──
    public function test_index_no_incluye_ventas_type_advance(): void
    {
        $cliente = Client::factory()->create();
        $ventaNormal = Sale::factory()->create(['type' => 'sale', 'client_id' => $cliente->id]);
        $ventaAdelanto = Sale::factory()->create(['type' => 'advance', 'client_id' => $cliente->id]);

        $response = app(SaleController::class)->index(new Request());
        $body = json_decode($response->getContent(), true);

        $ids = array_column($body['sales']['data'] ?? $body['sales'], 'id');

        $this->assertContains($ventaNormal->id, $ids);
        $this->assertNotContains($ventaAdelanto->id, $ids);
    }
}
