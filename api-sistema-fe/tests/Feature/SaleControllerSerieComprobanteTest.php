<?php

namespace Tests\Feature;

use App\Http\Controllers\Sale\SaleController;
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
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Módulo de series de comprobantes — Paso 4. Corre contra
// sistemafe_test_migrations (Postgres real) — nunca contra sv_facturacion
// ni ningún tenant real. TipoComprobante (central) también se redirige.
class SaleControllerSerieComprobanteTest extends TestCase
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
        // mismo fixture que GreenterServiceFormaPagoTest/ReservarCorrelativoTest,
        // sin esto cualquier User::factory() revienta con FK violation.
        DB::table('roles')->insert([
            'id' => 1,
            'name' => 'test-role-default',
            'guard_name' => 'api',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // El id=1 se insertó a mano — sincroniza la secuencia de Postgres
        // para que el próximo Role::create() (roles reales de cada test)
        // no intente reusar el mismo id=1 y choque con la unique constraint.
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function branchConSerie(string $codigo, string $serieTexto): array
    {
        $branch = Branch::create(['name' => 'Sede Test', 'is_active' => true]);
        $serie = SerieComprobante::create([
            'branch_id' => $branch->id,
            'tipo_comprobante_codigo' => $codigo,
            'moneda' => 'PEN',
            'serie' => $serieTexto,
            'correlativo_actual' => 0,
            'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'),
            'activo' => true,
        ]);

        return [$branch, $serie];
    }

    // Usuario con branch_id fijo y, opcionalmente, un permiso de emisión
    // real (rol nuevo por test — mismo criterio de aislamiento que el resto
    // de la suite, sin depender de roles preexistentes en la BD).
    private function usuarioConPermiso(int $branchId, ?string $permiso): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);

        $role = Role::create(['name' => 'rol-test-' . uniqid(), 'guard_name' => 'api']);
        if ($permiso) {
            $permission = Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']);
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
        $user->role_id = $role->id;
        $user->save();

        Auth::guard('api')->setUser($user->fresh());

        return $user->fresh();
    }

    private function resolverSerieComprobante(Request $request)
    {
        $controller = app(SaleController::class);
        $method = new ReflectionMethod(SaleController::class, 'resolverSerieComprobante');
        $method->setAccessible(true);

        return $method->invoke($controller, $request);
    }

    public function test_usuario_sin_permiso_emitir_factura_es_rechazado(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermiso($branch->id, null); // sin ningún permiso de emisión

        try {
            $this->resolverSerieComprobante(new Request([
                'tipo_comprobante_codigo' => '01',
                'currency' => 'PEN',
            ]));
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('emitir_factura', $e->getMessage());
        }
    }

    public function test_usuario_con_permiso_emitir_factura_resuelve_ok(): void
    {
        [$branch, $serie] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermiso($branch->id, 'emitir_factura');

        $resuelta = $this->resolverSerieComprobante(new Request([
            'tipo_comprobante_codigo' => '01',
            'currency' => 'PEN',
        ]));

        $this->assertSame($serie->id, $resuelta['serie']->id);
        $this->assertSame('01', $resuelta['tipo']->codigo);
        $this->assertSame($branch->id, $resuelta['branch_id']);
    }

    public function test_usuario_sin_permiso_emitir_nota_venta_es_rechazado(): void
    {
        [$branch, ] = $this->branchConSerie('NV', 'NV001');
        $this->usuarioConPermiso($branch->id, 'emitir_factura'); // tiene OTRO permiso, no el de NV

        try {
            $this->resolverSerieComprobante(new Request([
                'tipo_comprobante_codigo' => 'NV',
                'currency' => 'PEN',
            ]));
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('emitir_nota_venta', $e->getMessage());
        }
    }

    public function test_usuario_sin_sucursal_asignada_es_rechazado(): void
    {
        $user = User::factory()->create(['branch_id' => null]);
        $role = Role::create(['name' => 'rol-sin-sucursal-' . uniqid(), 'guard_name' => 'api']);
        $permission = Permission::firstOrCreate(['name' => 'emitir_boleta', 'guard_name' => 'api']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        Auth::guard('api')->setUser($user->fresh());

        try {
            $this->resolverSerieComprobante(new Request([
                'tipo_comprobante_codigo' => '03',
                'currency' => 'PEN',
            ]));
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('sucursal', $e->getMessage());
        }
    }

    public function test_tipo_comprobante_codigo_faltante_es_rechazado(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermiso($branch->id, 'emitir_factura');

        try {
            $this->resolverSerieComprobante(new Request(['currency' => 'PEN']));
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    // ── store() de punta a punta: venta NV a crédito ────────────────────
    // Confirma: se crea la venta con tipo_comprobante_codigo='NV', reserva
    // su correlativo DE INMEDIATO (a diferencia de un tipo fiscal), nunca
    // setea n_operacion, genera el cronograma de cuotas, y descuenta stock
    // — todo sin tocar GreenterService/Greenter en absoluto (store() nunca
    // lo referencia para ningún tipo de venta, confirmado por grep antes de
    // escribir este módulo).
    public function test_store_venta_nv_a_credito_reserva_correlativo_de_inmediato_y_no_usa_sunat(): void
    {
        [$branch, $serieNv] = $this->branchConSerie('NV', 'NV001');
        $this->usuarioConPermiso($branch->id, 'emitir_nota_venta');

        $categoria = Categorie::create(['title' => 'Categoría Test', 'state' => 1]);
        $producto = Product::create([
            'title' => 'Producto Test',
            'sku' => 'SKU-TEST-' . uniqid(),
            'categorie_id' => $categoria->id,
            'price_general' => 10,
            'price_company' => 10,
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
        $cliente = Client::factory()->create();

        $total = 118.00;

        $request = new Request([
            'date' => now()->format('Y-m-d'),
            'tipo_comprobante_codigo' => 'NV',
            'n_transaction' => '00000001',
            'client_id' => $cliente->id,
            'type_client' => 1,
            'cod_tipo_doc_cliente' => '1',
            'currency' => 'PEN',
            'is_exportacion' => 0,
            'destino' => 'nacional',
            'type_payment' => 2, // crédito
            'subtotal' => 100.00,
            'igv' => 18.00,
            'total' => $total,
            'discount' => 0,
            'discount_global' => 0,
            'retencion_igv' => 0,
            'state_payment' => 1,
            'debt' => $total,
            'paid_out' => 0,
            'credit_type' => 'cuotas_fijas',
            'cronograma' => [
                ['numero_cuota' => 1, 'monto_programado' => $total, 'fecha_vencimiento' => now()->addDays(30)->format('Y-m-d')],
            ],
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
            'payments' => [], // 100% a crédito, sin pago inicial — no exige caja abierta
            'advance_applications' => [],
        ]);

        $controller = app(SaleController::class);
        $response = $controller->store($request);
        $body = json_decode($response->getContent(), true);

        $this->assertSame(200, $body['code']);

        $venta = Sale::find($body['sale_id']);
        $this->assertNotNull($venta);

        // Tipo/serie resueltos correctamente, no el 'serie' de texto libre de antes.
        $this->assertSame('NV', $venta->tipo_comprobante_codigo);
        $this->assertSame($serieNv->id, $venta->serie_comprobante_id);
        $this->assertSame('NV001', $venta->serie);

        // Correlativo reservado DE INMEDIATO — a diferencia de un tipo
        // fiscal, que lo reserva recién en enviarSunat().
        $this->assertSame(1, $venta->correlativo);
        $this->assertSame(1, $serieNv->fresh()->correlativo_actual);

        // n_operacion NUNCA se setea para NV — exclusivo del envío SUNAT real.
        $this->assertNull($venta->n_operacion);
        $this->assertNull($venta->xml);
        $this->assertNull($venta->cdr);

        // Cronograma de cuotas generado igual que cualquier venta a crédito.
        $this->assertSame(1, $venta->installments()->count());

        // Stock descontado igual que cualquier venta.
        $this->assertSame(48, (int) $producto->fresh()->stock);
    }

    public function test_store_rechaza_si_usuario_no_tiene_permiso_para_el_tipo_elegido(): void
    {
        [$branch, ] = $this->branchConSerie('NV', 'NV001');
        $this->usuarioConPermiso($branch->id, 'emitir_factura'); // NO tiene emitir_nota_venta

        $request = new Request([
            'date' => now()->format('Y-m-d'),
            'tipo_comprobante_codigo' => 'NV',
            'currency' => 'PEN',
            'client_id' => Client::factory()->create()->id,
            'type_client' => 1,
            'type_payment' => 1,
            'retencion_igv' => 0,
            'sale_details' => [],
            'payments' => [],
        ]);

        $controller = app(SaleController::class);

        try {
            $controller->store($request);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('emitir_nota_venta', $e->getMessage());
        }

        $this->assertSame(0, Sale::count());
    }
}
