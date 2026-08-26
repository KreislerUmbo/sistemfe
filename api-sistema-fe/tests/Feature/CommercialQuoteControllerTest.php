<?php

namespace Tests\Feature;

use App\Http\Controllers\CommercialQuote\CommercialQuoteController;
use App\Models\Client\Client;
use App\Models\CommercialQuote\CommercialQuote;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Módulo nuevo "Cotizaciones Comerciales" — reemplaza a sales.state_sale
// (retirado). El objetivo central de este test es blindar que el módulo
// NUNCA toca products.stock/cash_movements/installments (última prueba),
// exactamente lo que sales.state_sale=2 hacía mal.
//
// Mismo patrón que AdvanceIntegridadTest: corre contra
// sistemafe_test_migrations (Postgres real), transacción por test
// revertida en tearDown(), controller invocado directo.
class CommercialQuoteControllerTest extends TestCase
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

    private function usuarioTest(): User
    {
        $user = User::factory()->create();

        $role = Role::create(['name' => 'rol-test-' . uniqid(), 'guard_name' => 'api']);
        foreach (['list_commercial_quote', 'register_commercial_quote', 'edit_commercial_quote', 'convert_commercial_quote'] as $permiso) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }
        $user->assignRole($role);
        $user->role_id = $role->id;
        $user->save();

        Auth::guard('api')->setUser($user->fresh());

        return $user->fresh();
    }

    private function productoTest(): Product
    {
        $categoria = Categorie::create(['title' => 'Categoría Test Cotización', 'state' => 1]);

        return Product::create([
            'title' => 'Producto Test Cotización',
            'sku' => 'SKU-COT-' . uniqid(),
            'categorie_id' => $categoria->id,
            'price_general' => 50,
            'price_company' => 50,
            'state' => 1,
            'unidad_medida' => 'NIU',
            'stock' => 20,
            'is_discount' => false,
            'disponiblidad' => 1,
            'include_igv' => false,
            'is_icbper' => false,
            'is_ivap' => false,
            'is_isc' => false,
            'is_especial_nota' => false,
            'tip_afe_igv_default' => '10',
        ]);
    }

    private function payloadBase(Product $producto, array $overrides = []): array
    {
        $base = [
            'client_id' => null,
            'client_name_free' => 'Prospecto Sin Registrar',
            'client_phone_free' => '999999999',
            'currency' => 'PEN',
            'discount_global' => 0,
            'valid_until' => now()->addDays(15)->format('Y-m-d'),
            'observacion' => 'Cotización de prueba',
            'items' => [[
                'product_id' => $producto->id,
                'quantity' => 2,
                'unit_price' => 50,
            ]],
        ];

        return array_replace($base, $overrides);
    }

    // ── Crear ────────────────────────────────────────────────────────────

    public function test_store_con_cliente_registrado_calcula_totales_en_backend(): void
    {
        $this->usuarioTest();
        $cliente = Client::factory()->create();
        $producto = $this->productoTest();

        $request = new Request($this->payloadBase($producto, [
            'client_id' => $cliente->id,
            'client_name_free' => null,
            'client_phone_free' => null,
            // Payload intencionalmente manda un total falso — el backend
            // debe ignorarlo y calcular el real desde los items.
            'subtotal' => 999999,
            'total' => 999999,
        ]));

        $resp = app(CommercialQuoteController::class)->store($request);
        $data = $resp->getData(true);

        $this->assertSame(200, $data['code']);

        $cotizacion = CommercialQuote::findOrFail($data['commercial_quote_id']);
        $this->assertMatchesRegularExpression('/^COT-\d{8}$/', $cotizacion->code);
        $this->assertSame(100.0, (float) $cotizacion->subtotal);
        $this->assertSame(100.0, (float) $cotizacion->total);
        $this->assertSame($cliente->id, $cotizacion->client_id);
        $this->assertNull($cotizacion->client_name_free);
    }

    public function test_store_con_client_name_free_sin_client_id(): void
    {
        $this->usuarioTest();
        $producto = $this->productoTest();

        $request = new Request($this->payloadBase($producto));

        $resp = app(CommercialQuoteController::class)->store($request);
        $data = $resp->getData(true);

        $this->assertSame(200, $data['code']);
        $cotizacion = CommercialQuote::findOrFail($data['commercial_quote_id']);
        $this->assertNull($cotizacion->client_id);
        $this->assertSame('Prospecto Sin Registrar', $cotizacion->client_name_free);
    }

    public function test_store_rechaza_sin_cliente_ni_nombre_libre(): void
    {
        $this->usuarioTest();
        $producto = $this->productoTest();

        $request = new Request($this->payloadBase($producto, [
            'client_id' => null,
            'client_name_free' => null,
        ]));

        try {
            app(CommercialQuoteController::class)->store($request);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_store_rechaza_item_sin_producto_ni_descripcion(): void
    {
        $this->usuarioTest();

        $request = new Request($this->payloadBase($this->productoTest(), [
            'items' => [['quantity' => 1, 'unit_price' => 10]],
        ]));

        try {
            app(CommercialQuoteController::class)->store($request);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_store_acepta_item_libre_sin_producto_con_descripcion(): void
    {
        $this->usuarioTest();

        $request = new Request($this->payloadBase($this->productoTest(), [
            'items' => [[
                'description' => 'Servicio de instalación (sin catálogo todavía)',
                'quantity' => 1,
                'unit_price' => 80,
            ]],
        ]));

        $resp = app(CommercialQuoteController::class)->store($request);
        $data = $resp->getData(true);

        $this->assertSame(200, $data['code']);
        $cotizacion = CommercialQuote::with('items')->findOrFail($data['commercial_quote_id']);
        $this->assertNull($cotizacion->items->first()->product_id);
        $this->assertSame(80.0, (float) $cotizacion->total);
    }

    // ── Editar ───────────────────────────────────────────────────────────

    public function test_update_rechaza_editar_contenido_si_no_esta_en_borrador_o_enviada(): void
    {
        $this->usuarioTest();
        $producto = $this->productoTest();
        $id = $this->crearCotizacion($producto);

        // borrador -> enviada -> aceptada (transiciones válidas)
        app(CommercialQuoteController::class)->update(new Request(['status' => 'enviada']), (string) $id);
        app(CommercialQuoteController::class)->update(new Request(['status' => 'aceptada']), (string) $id);

        $request = new Request(array_merge($this->payloadBase($producto), ['status' => 'aceptada']));

        try {
            app(CommercialQuoteController::class)->update($request, (string) $id);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_update_rechaza_si_ya_fue_convertida(): void
    {
        $this->usuarioTest();
        $producto = $this->productoTest();
        $id = $this->crearCotizacion($producto);
        $venta = \App\Models\Sale\Sale::factory()->create();

        DB::table('commercial_quotes')->where('id', $id)->update(['converted_sale_id' => $venta->id, 'converted_at' => now(), 'status' => 'aceptada']);

        $request = new Request(['status' => 'enviada']);

        try {
            app(CommercialQuoteController::class)->update($request, (string) $id);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString((string) $venta->id, $e->getMessage());
        }
    }

    public function test_update_rechaza_transicion_de_estado_invalida(): void
    {
        $this->usuarioTest();
        $producto = $this->productoTest();
        $id = $this->crearCotizacion($producto);

        // borrador -> aceptada directo no está permitido (falta pasar por 'enviada')
        $request = new Request(['status' => 'aceptada']);

        try {
            app(CommercialQuoteController::class)->update($request, (string) $id);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    // ── for-sale / mark-converted ────────────────────────────────────────

    public function test_para_venta_rechaza_estados_terminales(): void
    {
        $this->usuarioTest();
        $producto = $this->productoTest();
        $id = $this->crearCotizacion($producto);

        DB::table('commercial_quotes')->where('id', $id)->update(['status' => 'rechazada']);

        try {
            app(CommercialQuoteController::class)->paraVenta((string) $id);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_para_venta_rechaza_si_ya_convertida(): void
    {
        $this->usuarioTest();
        $producto = $this->productoTest();
        $id = $this->crearCotizacion($producto);
        $venta = \App\Models\Sale\Sale::factory()->create();

        DB::table('commercial_quotes')->where('id', $id)->update(['converted_sale_id' => $venta->id]);

        try {
            app(CommercialQuoteController::class)->paraVenta((string) $id);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString((string) $venta->id, $e->getMessage());
        }
    }

    public function test_para_venta_exitoso_devuelve_payload_esperado(): void
    {
        $this->usuarioTest();
        $cliente = Client::factory()->create();
        $producto = $this->productoTest();
        $id = $this->crearCotizacion($producto, ['client_id' => $cliente->id, 'client_name_free' => null, 'client_phone_free' => null]);

        $resp = app(CommercialQuoteController::class)->paraVenta((string) $id);
        $data = $resp->getData(true);

        $this->assertSame($cliente->id, $data['client']['id']);
        $this->assertCount(1, $data['items']);
        $this->assertSame($producto->id, $data['items'][0]['product_id']);
        $this->assertEquals(50.0, $data['items'][0]['unit_price']);
    }

    public function test_mark_converted_doble_llamada_la_segunda_falla(): void
    {
        $this->usuarioTest();
        $producto = $this->productoTest();
        $id = $this->crearCotizacion($producto);

        $venta = \App\Models\Sale\Sale::factory()->create();

        $resp1 = app(CommercialQuoteController::class)->marcarConvertida(new Request(['sale_id' => $venta->id]), (string) $id);
        $this->assertSame(200, $resp1->getData(true)['code']);

        try {
            app(CommercialQuoteController::class)->marcarConvertida(new Request(['sale_id' => $venta->id]), (string) $id);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $cotizacion = CommercialQuote::find($id);
        $this->assertSame($venta->id, $cotizacion->converted_sale_id);
        $this->assertSame('aceptada', $cotizacion->status);
    }

    // Bug real encontrado probando en vivo contra sandbox: valid_until/
    // converted_at sin $casts en el modelo llegan como string plano —
    // ?->format() explota en runtime ("Call to a member function format()
    // on string"). show()/index() son los únicos puntos que formatean
    // ambos campos, así que este es el test de regresión correcto.
    public function test_show_no_explota_formateando_valid_until_y_converted_at_reales(): void
    {
        $this->usuarioTest();
        $producto = $this->productoTest();
        $id = $this->crearCotizacion($producto, ['valid_until' => now()->addDays(10)->format('Y-m-d')]);

        $venta = \App\Models\Sale\Sale::factory()->create();
        app(CommercialQuoteController::class)->marcarConvertida(new Request(['sale_id' => $venta->id]), (string) $id);

        $resp = app(CommercialQuoteController::class)->show((string) $id);
        $data = $resp->getData(true)['commercial_quote'];

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['valid_until']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['converted_at']);

        $indexResp = app(CommercialQuoteController::class)->index(new Request());
        $this->assertSame(200, $indexResp->getStatusCode());
    }

    // ── El test que blinda la razón de ser del módulo ──────────────────

    public function test_crear_y_convertir_cotizacion_nunca_toca_stock_caja_ni_cuotas(): void
    {
        $this->usuarioTest();
        $producto = $this->productoTest();
        $stockAntes = $producto->stock;

        $cashMovementsAntes = DB::table('cash_movements')->count();
        $installmentsAntes = DB::table('installments')->count();

        $id = $this->crearCotizacion($producto);
        $venta = \App\Models\Sale\Sale::factory()->create();
        app(CommercialQuoteController::class)->marcarConvertida(new Request(['sale_id' => $venta->id]), (string) $id);

        $this->assertEquals($stockAntes, $producto->fresh()->stock);
        $this->assertSame($cashMovementsAntes, DB::table('cash_movements')->count());
        $this->assertSame($installmentsAntes, DB::table('installments')->count());
    }

    // ── Helper de fixture ───────────────────────────────────────────────

    private function crearCotizacion(Product $producto, array $overrides = []): int
    {
        $request = new Request($this->payloadBase($producto, $overrides));
        $resp = app(CommercialQuoteController::class)->store($request);

        return $resp->getData(true)['commercial_quote_id'];
    }
}
