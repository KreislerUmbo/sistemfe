<?php

namespace Tests\Feature;

use App\Http\Controllers\Advance\AdvanceController;
use App\Http\Controllers\Sale\FacturacionElectronicaController;
use App\Models\Cash\Branch;
use App\Models\Cash\CashRegister;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Models\Sale\Sale;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Módulo de series de comprobantes — cierre del gap real encontrado en
// AdvanceController::store() (creaba el comprobante del adelanto directo,
// sin pasar por resolverSerieComprobante()/SerieComprobanteService, así que
// serie_comprobante_id quedaba null y reservarCorrelativo() caía al
// fallback legado incluso para adelantos NUEVOS, no solo históricos).
// Corre contra sistemafe_test_migrations (Postgres real) — nunca contra
// sv_facturacion ni ningún tenant real.
class AdvanceControllerSerieComprobanteTest extends TestCase
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

        // users.role_id tiene default(1) a nivel de Postgres — mismo
        // fixture que el resto de la suite.
        DB::table('roles')->insert([
            'id' => 1,
            'name' => 'test-role-default',
            'guard_name' => 'api',
            'created_at' => now(),
            'updated_at' => now(),
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

    // Usuario con sucursal fija + sesión de caja abierta — AdvanceController
    // no exige ningún permiso de emisión (decisión explícita, ver diseño:
    // el tipo de comprobante de un adelanto se DERIVA del cliente, no se
    // elige), así que ningún rol/permiso especial hace falta acá, solo
    // caja abierta (guard incondicional de AdvanceController::store()).
    private function usuarioConSesionCaja(int $branchId): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);
        $role = Role::create(['name' => 'rol-adelanto-' . uniqid(), 'guard_name' => 'api']);
        $user->assignRole($role);
        $user->role_id = $role->id;
        $user->save();

        $cashRegister = CashRegister::create([
            'branch_id' => $branchId,
            'name' => 'Caja Test',
            'is_active' => true,
        ]);

        CashSession::create([
            'cash_register_id' => $cashRegister->id,
            'opened_by' => $user->id,
            'opening_amount' => 0,
            'opened_at' => now(),
            'status' => 'open',
        ]);

        Auth::guard('api')->setUser($user->fresh());

        return $user->fresh();
    }

    private function branchConSerie(string $codigo, string $serieTexto): array
    {
        $branch = Branch::create(['name' => 'Sede Adelanto Test', 'is_active' => true]);
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

    private function registrarAdelanto(Client $cliente, float $amount = 100.00): array
    {
        $request = new Request([
            'client_id' => $cliente->id,
            'amount' => $amount,
            'currency' => 'PEN',
            'payment_method' => 'EFECTIVO',
            'tip_afe_igv' => '10',
        ]);

        $controller = app(AdvanceController::class);
        $response = $controller->store($request);

        return json_decode($response->getContent(), true);
    }

    public function test_adelanto_cliente_ruc_resuelve_factura_y_puebla_serie_comprobante_id(): void
    {
        [$branch, $serieFactura] = $this->branchConSerie('01', 'F001');
        $this->usuarioConSesionCaja($branch->id);

        $cliente = Client::factory()->empresa()->create();

        $body = $this->registrarAdelanto($cliente);
        $venta = Sale::find($body['sale_id']);

        $this->assertNotNull($venta, 'No se pudo localizar la venta del adelanto creado.');
        $this->assertSame('01', $venta->tipo_comprobante_codigo);
        $this->assertSame($serieFactura->id, $venta->serie_comprobante_id);
        $this->assertSame('F001', $venta->serie);
        // Fiscal: el correlativo NO se reserva al crear, recién en enviarSunat().
        $this->assertNull($venta->correlativo);
    }

    public function test_adelanto_cliente_no_ruc_resuelve_boleta(): void
    {
        [$branch, $serieBoleta] = $this->branchConSerie('03', 'B001');
        $this->usuarioConSesionCaja($branch->id);

        $cliente = Client::factory()->create(); // default: DNI

        $this->registrarAdelanto($cliente);

        $venta = Sale::where('client_id', $cliente->id)->where('type', 'advance')->first();

        $this->assertNotNull($venta);
        $this->assertSame('03', $venta->tipo_comprobante_codigo);
        $this->assertSame($serieBoleta->id, $venta->serie_comprobante_id);
        $this->assertSame('B001', $venta->serie);
    }

    // Confirma el cierre real del gap: reservarCorrelativo() sobre la venta
    // del adelanto usa el mecanismo NUEVO (serie_comprobantes.correlativo_actual),
    // no el fallback legado (MAX(sales.correlativo)) — que es exactamente lo
    // que habría pasado con serie_comprobante_id=null antes de este fix.
    public function test_correlativo_del_adelanto_se_reserva_via_servicio_nuevo_no_fallback(): void
    {
        [$branch, $serieFactura] = $this->branchConSerie('01', 'F001');
        $this->usuarioConSesionCaja($branch->id);

        $cliente = Client::factory()->empresa()->create();
        $this->registrarAdelanto($cliente);

        $venta = Sale::where('client_id', $cliente->id)->where('type', 'advance')->first();
        $this->assertNotNull($venta->serie_comprobante_id, 'serie_comprobante_id no quedó poblado — el gap sigue abierto.');

        $controller = app(FacturacionElectronicaController::class);
        $method = new ReflectionMethod(FacturacionElectronicaController::class, 'reservarCorrelativo');
        $method->setAccessible(true);

        $correlativo = $method->invoke($controller, $venta);

        $this->assertSame(1, $correlativo);
        // La prueba de que usó el mecanismo NUEVO: la fila de
        // serie_comprobantes avanzó, no un MAX(sales.correlativo) sobre 'F001'.
        $this->assertSame(1, $serieFactura->fresh()->correlativo_actual);
        $this->assertSame(1, $venta->fresh()->correlativo);
    }

    // ── Lock real entre dos conexiones sobre la serie que usaría un
    // adelanto — mismo tipo de operación concurrente (dos cajeros
    // registrando adelantos al mismo tiempo contra la misma serie nueva)
    // que motivó todo este módulo. Mismo patrón ya usado dos veces
    // (ReservarCorrelativoTest, SerieComprobanteServiceTest): rompe el
    // "cero persistencia real" del resto de la clase a propósito, con
    // limpieza manual garantizada en finally{}.
    public function test_lock_bloquea_segunda_conexion_sobre_serie_usada_por_adelantos(): void
    {
        DB::commit();
        DB::connection('central')->commit();

        $branch = Branch::create(['name' => 'Sede Lock Adelanto', 'is_active' => true]);
        $serie = SerieComprobante::create([
            'branch_id' => $branch->id,
            'tipo_comprobante_codigo' => '03', // boleta — caso típico de adelanto de cliente DNI
            'moneda' => 'PEN',
            'serie' => 'B900',
            'correlativo_actual' => 0,
            'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'),
            'activo' => true,
        ]);

        $bloqueada = false;
        $mensajeError = null;

        try {
            DB::connection('pgsql')->beginTransaction();
            DB::connection('pgsql')->select(
                'select * from serie_comprobantes where id = ? for update',
                [$serie->id]
            );

            config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
            DB::purge('pgsql_b');
            DB::connection('pgsql_b')->beginTransaction();
            DB::connection('pgsql_b')->statement("SET LOCAL lock_timeout = '300ms'");

            try {
                DB::connection('pgsql_b')->select(
                    'select * from serie_comprobantes where id = ? for update',
                    [$serie->id]
                );
            } catch (\Throwable $e) {
                $bloqueada = true;
                $mensajeError = $e->getMessage();
            }

            DB::connection('pgsql_b')->rollBack();
            DB::connection('pgsql')->rollBack();
        } finally {
            DB::connection('pgsql')->table('serie_comprobantes')->where('id', $serie->id)->delete();
            DB::connection('pgsql')->table('branches')->where('id', $branch->id)->delete();
            // setUp() insertó roles.id=1 fuera de cualquier transacción que
            // sobreviva a este DB::commit() — sin este delete queda
            // commiteado de verdad y choca con el mismo fixture de otras
            // clases de test (mismo motivo que ReservarCorrelativoTest lo
            // limpia acá).
            DB::connection('pgsql')->table('roles')->where('id', 1)->delete();

            DB::beginTransaction();
            DB::connection('central')->beginTransaction();
        }

        $this->assertTrue(
            $bloqueada,
            'Se esperaba que la segunda conexión no pudiera tomar el lock de la serie del adelanto mientras la primera lo sostiene abierto.'
        );
        $this->assertNotNull($mensajeError);
    }
}
