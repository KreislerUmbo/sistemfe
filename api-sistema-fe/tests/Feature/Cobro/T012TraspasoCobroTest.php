<?php

namespace Tests\Feature\Cobro;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\Cash\Branch;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashRegister;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Models\Sale\Note;
use App\Models\Sale\Sale;
use App\Models\Sale\SalePayment;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use App\Services\Cobro\CobroVentaService;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-012: traspaso del cobro de un comprobante anulado con NC al comprobante nuevo de la misma
// reserva. Mismo armado que T011CobroFacturarReservaTest (Postgres real, transacción por test).
class T012TraspasoCobroTest extends TestCase
{
    use ConAccesoATodasLasReservas;

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

        $tenant = new \App\Models\Tenant();
        $tenant->id = 'test-tenant-' . uniqid();
        $tenant->facturacion_habilitada = true;
        app(\Stancl\Tenancy\Tenancy::class)->tenant = $tenant;

        $this->verTodasLasReservas();

        config(['agencia.cobro_al_facturar' => true]);
        PaymentMethod::firstOrCreate(
            ['code' => 'EFECTIVO'],
            ['name' => 'Efectivo', 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => true]
        )->update(['monedas' => ['PEN', 'USD'], 'is_active' => true, 'affects_cash_count' => true]);
    }

    protected function tearDown(): void
    {
        app(\Stancl\Tenancy\Tenancy::class)->tenant = null;
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    /** Reserva de un pasajero y un ítem de $precio en soles, con caja abierta del usuario que factura. */
    private function escenario(float $precio): array
    {
        $branch = Branch::create(['name' => 'Sede T012 ' . uniqid(), 'is_active' => true]);
        foreach (['01' => 'F', '03' => 'B'] as $codigo => $letra) {
            foreach (['PEN' => '1', 'USD' => '2'] as $m => $n) {
                SerieComprobante::create([
                    'branch_id' => $branch->id, 'tipo_comprobante_codigo' => $codigo, 'moneda' => $m,
                    'serie' => $letra . '00' . $n, 'correlativo_actual' => 0, 'correlativo_inicial' => 1,
                    'fecha_inicio' => HoraPeru::hoyTexto(), 'activo' => true,
                ]);
            }
        }

        $usuario = User::factory()->create(['branch_id' => $branch->id]);
        $rol = Role::create(['name' => 'rol-t012-' . uniqid(), 'guard_name' => 'api']);
        foreach (['emitir_factura', 'emitir_boleta'] as $permiso) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }
        $usuario->assignRole($rol);
        $usuario->role_id = $rol->id;
        $usuario->save();
        $usuario = $usuario->fresh();
        $this->verTodasLasReservas($usuario);

        $caja = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja T012', 'is_active' => true]);
        CashSession::create([
            'cash_register_id' => $caja->id, 'opened_by' => $usuario->id,
            'opening_amount' => 0, 'opened_at' => now(), 'status' => 'open',
        ]);

        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999), 'full_name' => 'Cliente T012',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T012-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $cp = DB::table('cotizacion_pasajeros')->insertGetId([
            'cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30, 'created_at' => now(), 'updated_at' => now(),
        ]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Servicio T012', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => $precio * 0.8,
            'precio_venta_snapshot' => $precio, 'precio_convertido' => $precio, 'pax_incluidos' => [$cp],
        ]);
        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        auth('api')->setUser($usuario);

        return [
            'reserva' => $reserva,
            'pasajero' => ReservaPasajero::where('reserva_id', $reserva->id)->first(),
            'usuario' => $usuario,
        ];
    }

    private function facturar(array $e, array $extra = []): Sale
    {
        $cuerpo = app(ReservaFacturacionController::class)->store(new Request($extra + [
            'pasajero_ids' => [$e['pasajero']->id],
            'client_id' => Client::factory()->empresa()->create()->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $e['reserva']->id)->getData(true);

        $this->assertSame(200, $cuerpo['code'], json_encode($cuerpo));

        return Sale::findOrFail($cuerpo['sale_id']);
    }

    private function falla(callable $accion): HttpException
    {
        try {
            $accion();
        } catch (HttpException $ex) {
            return $ex;
        }
        $this->fail('Se esperaba una HttpException.');
    }

    /** NC sobre la venta; si queda aceptada, libera la reserva como en producción. */
    private function nota(Sale $venta, float $monto, string $tipo = 'total', string $status = 'aceptado'): Note
    {
        $nota = Note::create([
            'sale_id' => $venta->id, 'tipo_doc' => '07', 'tipo_doc_afectado' => '01',
            'serie_afectada' => $venta->serie, 'correlativo_afectado' => 1, 'serie' => 'FC01',
            'cod_motivo' => '01', 'des_motivo' => 'Anulación de la operación', 'tipo_afectacion' => $tipo,
            'client_id' => $venta->client_id, 'cod_tipo_doc_cliente' => '6', 'currency' => $venta->currency,
            'status' => $status, 'reponer_stock' => false, 'user_id' => auth('api')->id(),
            'mto_imp_venta' => $monto,
        ]);
        if ($status === 'aceptado') {
            ReservaVenta::liberarPorNotaDeCredito($venta->id, $tipo, []);
        }

        return $nota;
    }

    private function disponible(array $e, string $moneda = 'PEN'): array
    {
        return app(CobroVentaService::class)->disponiblesParaTraspasar((int) $e['reserva']->id, $moneda);
    }

    // CA1
    public function test_ca1_nc_total_y_refacturar_con_traspaso_no_mueve_la_caja(): void
    {
        $e = $this->escenario(500);
        $v1 = $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]]]);
        $this->nota($v1, 500);
        $movimientos = CashMovement::count();

        $v2 = $this->facturar($e, ['traspasos' => [['sale_id' => $v1->id, 'monto' => 500]]]);

        $v2->refresh();
        $this->assertSame(3, (int) $v2->state_payment);
        $this->assertEquals(0.0, (float) $v2->debt);
        $this->assertEquals(500.0, (float) $v2->paid_out);
        $pago = SalePayment::where('sale_id', $v2->id)->sole();
        $this->assertSame('traspaso', $pago->origen);
        $this->assertSame($v1->id, (int) $pago->traspasado_de_sale_id);
        $this->assertEquals(500.0, (float) $pago->amount);
        $this->assertSame($movimientos, CashMovement::count(), 'un traspaso no crea movimientos de caja');
        $this->assertSame([], $this->disponible($e));
    }

    // CA2
    public function test_ca2_nc_parcial_acota_el_disponible_al_monto_de_la_nota(): void
    {
        $e = $this->escenario(500);
        $v1 = $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]]]);
        $this->nota($v1, 200, 'parcial');

        $this->assertEquals(200.0, $this->disponible($e)[$v1->id]['disponible']);
    }

    // Revisión: venta pagada en parte. La NC baja primero la deuda; solo lo pagado de más es traspasable.
    public function test_venta_pagada_en_parte_solo_traspasa_lo_que_excede_la_deuda(): void
    {
        $e = $this->escenario(500);
        $v1 = $this->facturar($e, [
            'condicion_pago' => 'credito',
            'cronograma' => [[
                'numero_cuota' => 1, 'monto_programado' => 500,
                'fecha_vencimiento' => \Carbon\Carbon::parse(HoraPeru::hoyTexto())->addDays(30)->toDateString(),
            ]],
        ]);
        SalePayment::create([
            'sale_id' => $v1->id, 'method_payment' => 'EFECTIVO', 'amount' => 100,
            'date_payment' => HoraPeru::hoyTexto(), 'moneda_pago' => 'PEN', 'monto_pagado' => 100, 'tipo_cambio' => 1,
        ]);

        // Debe 500 - 200 = 300 y pagó 100: nada a favor.
        $this->nota($v1, 200, 'parcial');
        $this->assertSame([], $this->disponible($e));

        // Con NC por el total, los 100 pagados quedan a favor.
        $this->nota($v1, 300, 'parcial');
        $this->assertEquals(100.0, $this->disponible($e)[$v1->id]['disponible']);
    }

    // CA3
    public function test_ca3_traspaso_mayor_al_disponible_es_422_y_no_deja_nada(): void
    {
        $e = $this->escenario(500);
        $v1 = $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]]]);
        $this->nota($v1, 500);
        $ventas = Sale::count();
        $pagos = SalePayment::count();

        $ex = $this->falla(fn () => $this->facturar($e, ['traspasos' => [['sale_id' => $v1->id, 'monto' => 500.01]]]));

        $this->assertSame(422, $ex->getStatusCode());
        $this->assertStringContainsString('disponibles para traspasar', $ex->getMessage());
        $this->assertSame($ventas, Sale::count());
        $this->assertSame($pagos, SalePayment::count());
    }

    // CA4
    public function test_ca4_el_segundo_traspaso_ve_el_disponible_descontado(): void
    {
        $e = $this->escenario(500);
        $v1 = $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]]]);
        $this->nota($v1, 500);

        $v2 = $this->facturar($e, [
            'traspasos' => [['sale_id' => $v1->id, 'monto' => 300]],
            'pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 200]],
        ]);
        $this->nota($v2, 500);

        $this->assertEquals(200.0, $this->disponible($e)[$v1->id]['disponible']);

        $ex = $this->falla(fn () => $this->facturar($e, ['traspasos' => [['sale_id' => $v1->id, 'monto' => 300]]]));
        $this->assertSame(422, $ex->getStatusCode());

        $v3 = $this->facturar($e, ['traspasos' => [
            ['sale_id' => $v1->id, 'monto' => 200],
            ['sale_id' => $v2->id, 'monto' => 300],
        ]]);
        $this->assertSame(3, (int) $v3->fresh()->state_payment);
        $this->assertArrayNotHasKey($v1->id, $this->disponible($e));
        $this->assertEquals(200.0, $this->disponible($e)[$v2->id]['disponible']);
    }

    // CA5
    public function test_ca5_otra_reserva_u_otra_moneda_no_se_ofrece_y_se_rechaza(): void
    {
        $a = $this->escenario(500);
        $vA = $this->facturar($a, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]]]);
        $this->nota($vA, 500);

        $b = $this->escenario(500);
        $this->assertSame([], $this->disponible($b), 'la venta de otra reserva no se ofrece');
        $ex = $this->falla(fn () => $this->facturar($b, ['traspasos' => [['sale_id' => $vA->id, 'monto' => 500]]]));
        $this->assertSame(422, $ex->getStatusCode());
        $this->assertStringContainsString('no tiene cobro disponible', $ex->getMessage());

        auth('api')->setUser($a['usuario']);
        $this->assertArrayHasKey($vA->id, $this->disponible($a));
        $this->assertSame([], $this->disponible($a, 'USD'), 'otra moneda no se ofrece');
        $ex = $this->falla(fn () => $this->facturar($a, [
            'moneda_facturacion' => 'USD', 'tipo_cambio_conversion' => 3.75, 'motivo_cambio_moneda' => 'prueba',
            'traspasos' => [['sale_id' => $vA->id, 'monto' => 100]],
        ]));
        $this->assertSame(422, $ex->getStatusCode());
        $this->assertStringContainsString('no tiene cobro disponible', $ex->getMessage());
    }

    // CA6
    public function test_ca6_traspaso_y_pago_nuevo_juntos_cubren_el_saldo(): void
    {
        $e = $this->escenario(500);
        $v1 = $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]]]);
        $this->nota($v1, 500);
        $movimientos = CashMovement::count();

        $v2 = $this->facturar($e, [
            'traspasos' => [['sale_id' => $v1->id, 'monto' => 300]],
            'pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 200]],
        ]);

        $this->assertSame(3, (int) $v2->fresh()->state_payment);
        $this->assertEquals(500.0, (float) SalePayment::where('sale_id', $v2->id)->sum('amount'));
        $this->assertSame($movimientos + 1, CashMovement::count(), 'solo el pago nuevo entra a la caja');

        // Si lo traspasado no alcanza solo, 422.
        $this->nota($v2, 500);
        $ex = $this->falla(fn () => $this->facturar($e, ['traspasos' => [['sale_id' => $v1->id, 'monto' => 100]]]));
        $this->assertSame(422, $ex->getStatusCode());
    }

    // La pantalla recibe los saldos traspasables en la vista previa de facturar.
    public function test_la_vista_previa_ofrece_los_saldos_traspasables(): void
    {
        $e = $this->escenario(500);
        $v1 = $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]]]);
        $this->nota($v1, 500);

        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$e['pasajero']->id],
        ]), (string) $e['reserva']->id)->getData(true);

        $this->assertCount(1, $preview['traspasos_disponibles']);
        $this->assertSame($v1->id, $preview['traspasos_disponibles'][0]['sale_id']);
        $this->assertEquals(500.0, $preview['traspasos_disponibles'][0]['disponible']);
        $this->assertNotSame('', $preview['traspasos_disponibles'][0]['comprobante']);
    }

    // CA7
    public function test_ca7_nc_sin_aceptar_no_deja_disponible(): void
    {
        $e = $this->escenario(500);
        $v1 = $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]]]);
        $this->nota($v1, 500, 'total', 'pendiente');

        $this->assertSame([], $this->disponible($e));
    }

    // Revisión: el vuelto se guarda dentro de `amount` (600 entregados por 500), pero ya salió de
    // caja: no se ofrece mientras la factura sigue vigente ni infla lo traspasable tras la NC.
    public function test_el_vuelto_no_cuenta_como_disponible(): void
    {
        $e = $this->escenario(500);
        $v1 = $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 600]]]);
        $this->assertEquals(100.0, (float) $v1->fresh()->vuelto_monto);

        $this->assertSame([], $this->disponible($e));

        $this->nota($v1, 500);
        $this->assertEquals(500.0, $this->disponible($e)[$v1->id]['disponible']);
    }
}
