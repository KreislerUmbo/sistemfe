<?php

namespace Tests\Feature;

use App\Http\Controllers\CommercialQuote\CommercialQuoteAnticipoController;
use App\Http\Controllers\CommercialQuote\CommercialQuoteController;
use App\Models\Advance\Advance;
use App\Models\Advance\AdvanceApplication;
use App\Models\Cash\Branch;
use App\Models\Cash\CashRegister;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Models\CommercialQuote\CommercialQuote;
use App\Models\CommercialQuote\CommercialQuoteAnticipo;
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

// Cierra el gap señalado por el usuario justo después de cerrar el módulo
// "Cotizaciones Comerciales": un cliente puede querer dejar un anticipo
// para arrancar el trabajo ANTES de que la cotización se convierta en
// venta. Mismo patrón que ReservaAnticipoTest (Agencia de Viajes) — acá no
// hace falta mockear tenant('facturacion_habilitada') porque este
// controller no lo consulta.
class CommercialQuoteAnticipoControllerTest extends TestCase
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
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

        PaymentMethod::firstOrCreate(
            ['code' => 'EFECTIVO'],
            ['name' => 'Efectivo', 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => true]
        );
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    // Usuario con permisos + caja abierta — CommercialQuoteAnticipoController::
    // store() llama AdvanceController::store() por dentro, que exige caja
    // abierta (Módulo Caja — Fase 6, guard incondicional).
    private function usuarioCompleto(): User
    {
        $branch = Branch::create(['name' => 'Sede Test Anticipos Cotización', 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $branch->id, 'tipo_comprobante_codigo' => '01', 'moneda' => 'PEN',
            'serie' => 'F001', 'correlativo_actual' => 0, 'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'), 'activo' => true,
        ]);
        SerieComprobante::create([
            'branch_id' => $branch->id, 'tipo_comprobante_codigo' => '03', 'moneda' => 'PEN',
            'serie' => 'B001', 'correlativo_actual' => 0, 'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'), 'activo' => true,
        ]);

        $user = User::factory()->create(['branch_id' => $branch->id]);
        $role = Role::create(['name' => 'rol-test-' . uniqid(), 'guard_name' => 'api']);
        foreach (['list_commercial_quote', 'register_commercial_quote', 'edit_commercial_quote', 'convert_commercial_quote'] as $permiso) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }
        $user->assignRole($role);
        $user->role_id = $role->id;
        $user->save();

        $cashRegister = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja Test', 'is_active' => true]);
        CashSession::create([
            'cash_register_id' => $cashRegister->id, 'opened_by' => $user->id,
            'opening_amount' => 0, 'opened_at' => now(), 'status' => 'open',
        ]);

        Auth::guard('api')->setUser($user->fresh());

        return $user->fresh();
    }

    private function productoTest(): Product
    {
        $categoria = Categorie::create(['title' => 'Categoría Test Anticipos', 'state' => 1]);

        return Product::create([
            'title' => 'Producto Test Anticipos', 'sku' => 'SKU-ANT-' . uniqid(),
            'categorie_id' => $categoria->id, 'price_general' => 50, 'price_company' => 50,
            'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 20, 'is_discount' => false,
            'disponiblidad' => 1, 'include_igv' => false, 'is_icbper' => false, 'is_ivap' => false,
            'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
        ]);
    }

    private function crearCotizacion(?int $clientId, Product $producto): CommercialQuote
    {
        $request = new Request([
            'client_id' => $clientId,
            'client_name_free' => $clientId ? null : 'Prospecto Sin Registrar',
            'currency' => 'PEN',
            'items' => [['product_id' => $producto->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $resp = app(CommercialQuoteController::class)->store($request);

        return CommercialQuote::findOrFail($resp->getData(true)['commercial_quote_id']);
    }

    // ── store() ──────────────────────────────────────────────────────────

    public function test_store_crea_advance_y_lo_etiqueta_a_la_cotizacion(): void
    {
        $this->usuarioCompleto();
        $cliente = Client::factory()->create();
        $cotizacion = $this->crearCotizacion($cliente->id, $this->productoTest());

        // client_id en el payload debe ser IGNORADO — se deriva de la
        // cotización, nunca del payload (mismo blindaje que reserva_anticipos).
        $response = app(CommercialQuoteAnticipoController::class)->store(new Request([
            'monto' => 50.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
            'client_id' => 999999,
        ]), (string) $cotizacion->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));
        $this->assertSame(1, Advance::count());
        $this->assertSame(1, CommercialQuoteAnticipo::count());

        $advance = Advance::first();
        $this->assertSame($cliente->id, $advance->client_id);
        $this->assertSame(50.0, (float) $advance->amount);
        $this->assertSame('PEN', $advance->currency);

        $anticipo = CommercialQuoteAnticipo::first();
        $this->assertSame($cotizacion->id, $anticipo->commercial_quote_id);
        $this->assertSame($advance->id, $anticipo->advance_id);
    }

    public function test_store_rechaza_sin_cliente_registrado(): void
    {
        $this->usuarioCompleto();
        $cotizacion = $this->crearCotizacion(null, $this->productoTest());

        try {
            app(CommercialQuoteAnticipoController::class)->store(new Request([
                'monto' => 50.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
            ]), (string) $cotizacion->id);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(0, Advance::count());
    }

    public function test_store_rechaza_si_ya_convertida(): void
    {
        $this->usuarioCompleto();
        $cliente = Client::factory()->create();
        $cotizacion = $this->crearCotizacion($cliente->id, $this->productoTest());
        $venta = Sale::factory()->create();
        DB::table('commercial_quotes')->where('id', $cotizacion->id)->update(['converted_sale_id' => $venta->id]);

        try {
            app(CommercialQuoteAnticipoController::class)->store(new Request([
                'monto' => 50.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
            ]), (string) $cotizacion->id);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString((string) $venta->id, $e->getMessage());
        }
        $this->assertSame(0, Advance::count());
    }

    public function test_store_rechaza_estados_terminales(): void
    {
        $this->usuarioCompleto();
        $cliente = Client::factory()->create();
        $cotizacion = $this->crearCotizacion($cliente->id, $this->productoTest());
        DB::table('commercial_quotes')->where('id', $cotizacion->id)->update(['status' => 'anulada']);

        try {
            app(CommercialQuoteAnticipoController::class)->store(new Request([
                'monto' => 50.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
            ]), (string) $cotizacion->id);
            $this->fail('Se esperaba HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(0, Advance::count());
    }

    // ── destroy() ────────────────────────────────────────────────────────

    public function test_destroy_bloqueado_si_el_advance_ya_se_aplico(): void
    {
        $this->usuarioCompleto();
        $cliente = Client::factory()->create();
        $cotizacion = $this->crearCotizacion($cliente->id, $this->productoTest());

        app(CommercialQuoteAnticipoController::class)->store(new Request([
            'monto' => 50.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $cotizacion->id);
        $anticipo = CommercialQuoteAnticipo::first();

        $ventaCualquiera = Sale::factory()->create(['client_id' => $anticipo->advance->client_id]);
        AdvanceApplication::create([
            'advance_id' => $anticipo->advance_id, 'sale_id' => $ventaCualquiera->id, 'amount_applied' => 10.00,
        ]);

        $destroyResponse = app(CommercialQuoteAnticipoController::class)->destroy((string) $anticipo->id);
        $this->assertSame(422, $destroyResponse->getStatusCode());
        $this->assertSame(1, CommercialQuoteAnticipo::count(), 'no debió borrarse');
    }

    public function test_destroy_permitido_si_nunca_se_aplico(): void
    {
        $this->usuarioCompleto();
        $cliente = Client::factory()->create();
        $cotizacion = $this->crearCotizacion($cliente->id, $this->productoTest());

        app(CommercialQuoteAnticipoController::class)->store(new Request([
            'monto' => 50.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $cotizacion->id);
        $anticipo = CommercialQuoteAnticipo::first();

        $response = app(CommercialQuoteAnticipoController::class)->destroy((string) $anticipo->id);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, CommercialQuoteAnticipo::count());
        // El Advance en sí sigue existiendo — solo se quitó el tag.
        $this->assertSame(1, Advance::count());
    }

    // ── El anticipo aparece disponible para aplicar en show() ────────────
    public function test_show_incluye_el_anticipo_con_su_saldo_disponible(): void
    {
        $this->usuarioCompleto();
        $cliente = Client::factory()->create();
        $cotizacion = $this->crearCotizacion($cliente->id, $this->productoTest());

        app(CommercialQuoteAnticipoController::class)->store(new Request([
            'monto' => 50.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $cotizacion->id);

        $resp = app(CommercialQuoteController::class)->show((string) $cotizacion->id);
        $data = $resp->getData(true)['commercial_quote'];

        $this->assertCount(1, $data['anticipos']);
        $this->assertEquals(50.0, $data['anticipos'][0]['disponible']);
        $this->assertSame('PEN', $data['anticipos'][0]['currency']);
        $this->assertFalse($data['anticipos'][0]['sunat_enviado']);
    }
}
