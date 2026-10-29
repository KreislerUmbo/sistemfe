<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaAnticipoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\Cash\Branch;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashRegister;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Models\Sale\Sale;
use App\Models\Sale\SalePayment;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-011: cobro al contado al facturar una reserva (CobroVentaService dentro de
// ReservaFacturacionController::store()). Mismo armado que ReservaFacturacionTest:
// Postgres real, transacción por test, controllers invocados directo.
class T011CobroFacturarReservaTest extends TestCase
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
        $this->formaDePago('EFECTIVO', ['PEN', 'USD'], true);
        $this->formaDePago('YAPE', ['PEN'], false);
    }

    protected function tearDown(): void
    {
        app(\Stancl\Tenancy\Tenancy::class)->tenant = null;
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function formaDePago(string $code, array $monedas, bool $efectivo): void
    {
        PaymentMethod::firstOrCreate(
            ['code' => $code],
            ['name' => ucfirst(strtolower($code)), 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => $efectivo]
        )->update(['monedas' => $monedas, 'is_active' => true, 'affects_cash_count' => $efectivo]);
    }

    /**
     * Sucursal con series 01/03 en PEN y USD, usuario que factura (y registra anticipos), y una
     * reserva de un pasajero y un ítem de $precio en $moneda. $conCaja abre la caja del usuario.
     *
     * @return array{reserva: \App\Models\AgenciaViajes\Reserva, pasajero: ReservaPasajero, usuario: User, sesion: ?CashSession}
     */
    private function escenario(string $moneda, float $precio, bool $conCaja = true): array
    {
        $branch = Branch::create(['name' => 'Sede T011 ' . uniqid(), 'is_active' => true]);
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
        $rol = Role::create(['name' => 'rol-t011-' . uniqid(), 'guard_name' => 'api']);
        foreach (['emitir_factura', 'emitir_boleta', 'register_advance'] as $permiso) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }
        $usuario->assignRole($rol);
        $usuario->role_id = $rol->id;
        $usuario->save();
        $usuario = $usuario->fresh();
        $this->verTodasLasReservas($usuario);

        $sesion = null;
        if ($conCaja) {
            $caja = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja T011', 'is_active' => true]);
            $sesion = CashSession::create([
                'cash_register_id' => $caja->id, 'opened_by' => $usuario->id,
                'opening_amount' => 0, 'opened_at' => now(), 'status' => 'open',
            ]);
        }

        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999), 'full_name' => 'Cliente T011',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T011-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => $moneda, 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $cp = DB::table('cotizacion_pasajeros')->insertGetId([
            'cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30, 'created_at' => now(), 'updated_at' => now(),
        ]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Servicio T011', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => $moneda, 'costo_snapshot' => $precio * 0.8,
            'precio_venta_snapshot' => $precio, 'precio_convertido' => $precio, 'pax_incluidos' => [$cp],
        ]);
        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        auth('api')->setUser($usuario);

        return [
            'reserva' => $reserva,
            'pasajero' => ReservaPasajero::where('reserva_id', $reserva->id)->first(),
            'usuario' => $usuario,
            'sesion' => $sesion,
        ];
    }

    private function facturar(array $e, array $extra = [])
    {
        return app(ReservaFacturacionController::class)->store(new Request($extra + [
            'pasajero_ids' => [$e['pasajero']->id],
            'client_id' => Client::factory()->empresa()->create()->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $e['reserva']->id);
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

    private function movimientosDe(Sale $venta)
    {
        return CashMovement::where('reference_type', 'sale')->where('reference_id', $venta->id)->orderBy('id')->get();
    }

    // CA1
    public function test_ca1_contado_con_efectivo_deja_la_venta_cobrada_y_el_movimiento_en_caja(): void
    {
        $e = $this->escenario('PEN', 500);

        $cuerpo = $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]]])->getData(true);

        $this->assertSame(200, $cuerpo['code'], json_encode($cuerpo));
        $this->assertSame('Reserva facturada y cobrada.', $cuerpo['message']);
        $venta = Sale::find($cuerpo['sale_id']);
        $this->assertSame(3, (int) $venta->state_payment);
        $this->assertEquals(0.0, (float) $venta->debt);
        $this->assertEquals(0.0, (float) $venta->saldo_pendiente);
        $this->assertEquals(500.0, (float) $venta->paid_out);
        $this->assertSame(1, SalePayment::where('sale_id', $venta->id)->count());
        $mov = $this->movimientosDe($venta)->sole();
        $this->assertSame($e['sesion']->id, (int) $mov->cash_session_id);
        $this->assertSame('sale_payment', $mov->type);
        $this->assertEquals(500.0, (float) $mov->amount);
    }

    // CA2
    public function test_ca2_pago_mixto_efectivo_y_yape_con_numero_de_operacion(): void
    {
        $e = $this->escenario('PEN', 500);

        $cuerpo = $this->facturar($e, ['pagos' => [
            ['method_payment' => 'EFECTIVO', 'monto_pagado' => 200],
            ['method_payment' => 'YAPE', 'monto_pagado' => 300, 'nro_operacion' => '987654'],
        ]])->getData(true);

        $this->assertSame(200, $cuerpo['code'], json_encode($cuerpo));
        $venta = Sale::find($cuerpo['sale_id']);
        $this->assertSame(2, SalePayment::where('sale_id', $venta->id)->count());
        $this->assertSame('987654', SalePayment::where('sale_id', $venta->id)->where('method_payment', 'YAPE')->value('nro_operacion'));
        $this->assertCount(2, $this->movimientosDe($venta));
    }

    // CA3
    public function test_ca3_venta_en_dolares_pagada_con_dolares_y_soles(): void
    {
        $e = $this->escenario('USD', 1000);

        $cuerpo = $this->facturar($e, ['pagos' => [
            ['method_payment' => 'EFECTIVO', 'moneda_pago' => 'USD', 'monto_pagado' => 600],
            ['method_payment' => 'EFECTIVO', 'moneda_pago' => 'PEN', 'monto_pagado' => 1500, 'tipo_cambio' => 3.75],
        ]])->getData(true);

        $this->assertSame(200, $cuerpo['code'], json_encode($cuerpo));
        $venta = Sale::find($cuerpo['sale_id']);
        $this->assertSame('USD', $venta->currency);
        $this->assertEqualsCanonicalizing([600.0, 400.0], SalePayment::where('sale_id', $venta->id)->pluck('amount')->map(fn ($m) => (float) $m)->all());
        $movs = $this->movimientosDe($venta);
        $this->assertEquals(600.0, (float) $movs->firstWhere('currency', 'USD')->amount);
        $this->assertEquals(1500.0, (float) $movs->firstWhere('currency', 'PEN')->amount);
    }

    // CA4
    public function test_ca4_lo_cobrado_no_cubre_el_saldo_y_no_queda_nada_escrito(): void
    {
        $e = $this->escenario('PEN', 500);
        $ventas = Sale::count();
        $pagos = SalePayment::count();
        $movs = CashMovement::count();

        $ex = $this->falla(fn () => $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 450]]]));

        $this->assertSame(422, $ex->getStatusCode());
        $this->assertSame($ventas, Sale::count());
        $this->assertSame($pagos, SalePayment::count());
        $this->assertSame($movs, CashMovement::count());
    }

    // CA5
    public function test_ca5_sin_caja_abierta_no_se_emite_nada(): void
    {
        $e = $this->escenario('PEN', 500, conCaja: false);
        $ventas = Sale::count();

        $ex = $this->falla(fn () => $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]]]));

        $this->assertSame(422, $ex->getStatusCode());
        $this->assertStringContainsString('Abre tu caja para cobrar', $ex->getMessage());
        $this->assertSame($ventas, Sale::count());
    }

    // CA6
    public function test_ca6_anticipo_que_cubre_todo_se_emite_sin_pagos_y_con_pagos_se_rechaza(): void
    {
        $e = $this->escenario('PEN', 100);
        app(ReservaAnticipoController::class)->store(new Request([
            'monto' => 100.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $e['reserva']->id);

        // Un anticipo solo se aplica cuando SUNAT ya lo aceptó (n_operacion).
        \App\Models\Advance\Advance::firstOrFail()->sale->update(['n_operacion' => 'B001-00000001']);
        // El anticipo se aplica solo a comprobantes del mismo cliente que lo pagó: boleta a ese cliente.
        $mismoCliente = ['client_id' => $e['reserva']->alternativa->cotizacion->cliente_id, 'tipo_comprobante_codigo' => '03'];
        $ex = $this->falla(fn () => $this->facturar($e, $mismoCliente + ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 10]]]));
        $this->assertSame(422, $ex->getStatusCode());
        $this->assertStringContainsString('no hay nada que cobrar', $ex->getMessage());

        $e['sesion']->update(['status' => 'closed']); // sin caja: no hace falta, no se cobra nada
        $cuerpo = $this->facturar($e, $mismoCliente)->getData(true);

        $this->assertSame(200, $cuerpo['code'], json_encode($cuerpo));
        $this->assertSame(0, SalePayment::where('sale_id', $cuerpo['sale_id'])->count());
    }

    // CA7
    public function test_ca7_a_credito_no_se_cobra_al_facturar(): void
    {
        $e = $this->escenario('PEN', 500);
        $cronograma = [[
            'numero_cuota' => 1, 'monto_programado' => 500,
            'fecha_vencimiento' => \Carbon\Carbon::parse(HoraPeru::hoyTexto())->addDays(30)->toDateString(),
        ]];

        $ex = $this->falla(fn () => $this->facturar($e, [
            'condicion_pago' => 'credito', 'cronograma' => $cronograma,
            'pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]],
        ]));
        $this->assertSame(422, $ex->getStatusCode());
        $this->assertStringContainsString('A crédito no se cobra al facturar', $ex->getMessage());

        $cuerpo = $this->facturar($e, ['condicion_pago' => 'credito', 'cronograma' => $cronograma])->getData(true);
        $this->assertSame(200, $cuerpo['code'], json_encode($cuerpo));
        $this->assertSame(1, (int) Sale::find($cuerpo['sale_id'])->state_payment);
    }

    // CA8
    public function test_ca8_vuelto_en_soles_de_un_pago_en_dolares(): void
    {
        $e = $this->escenario('PEN', 3700);

        $cuerpo = $this->facturar($e, [
            'pagos' => [['method_payment' => 'EFECTIVO', 'moneda_pago' => 'USD', 'monto_pagado' => 1000, 'tipo_cambio' => 3.75]],
            'vuelto_moneda' => 'PEN',
        ])->getData(true);

        $this->assertSame(200, $cuerpo['code'], json_encode($cuerpo));
        $venta = Sale::find($cuerpo['sale_id']);
        $this->assertEquals(50.0, (float) $venta->vuelto_monto);
        $this->assertSame('PEN', $venta->vuelto_moneda);
        $salida = $this->movimientosDe($venta)->firstWhere('type', 'sale_change');
        $this->assertSame('out', $salida->direction);
        $this->assertEquals(50.0, (float) $salida->amount);
        $this->assertSame('PEN', $salida->currency);
    }

    // CA9
    public function test_ca9_contado_sin_pagos_pide_registrar_el_cobro(): void
    {
        $e = $this->escenario('PEN', 500);
        $ventas = Sale::count();

        $ex = $this->falla(fn () => $this->facturar($e));

        $this->assertSame(422, $ex->getStatusCode());
        $this->assertStringContainsString('Al contado registra el cobro', $ex->getMessage());
        $this->assertSame($ventas, Sale::count());
    }

    // CA10
    public function test_ca10_con_el_interruptor_apagado_se_emite_como_antes(): void
    {
        config(['agencia.cobro_al_facturar' => false]);
        $e = $this->escenario('PEN', 500);

        $ex = $this->falla(fn () => $this->facturar($e, ['pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 500]]]));
        $this->assertSame(422, $ex->getStatusCode());
        $this->assertStringContainsString('todavía no está habilitado', $ex->getMessage());

        $cuerpo = $this->facturar($e)->getData(true);
        $this->assertSame(200, $cuerpo['code'], json_encode($cuerpo));
        $venta = Sale::find($cuerpo['sale_id']);
        $this->assertSame(1, (int) $venta->state_payment);
        $this->assertCount(0, $this->movimientosDe($venta));

        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$e['pasajero']->id],
        ]), (string) $e['reserva']->id)->getData(true);
        $this->assertFalse($preview['cobro_habilitado']);
    }

    public function test_la_vista_previa_informa_formas_de_pago_caja_y_tipo_de_cambio(): void
    {
        $e = $this->escenario('PEN', 500);

        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$e['pasajero']->id],
        ]), (string) $e['reserva']->id)->getData(true);

        $this->assertTrue($preview['cobro_habilitado']);
        $this->assertTrue($preview['caja_abierta']);
        $yape = collect($preview['formas_pago'])->firstWhere('code', 'YAPE');
        $this->assertSame(['PEN'], $yape['monedas']);
        $this->assertArrayHasKey('tipo_cambio_dia', $preview);

        $e['sesion']->update(['status' => 'closed']);
        $sinCaja = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$e['pasajero']->id],
        ]), (string) $e['reserva']->id)->getData(true);
        $this->assertFalse($sinCaja['caja_abierta']);
    }
}
