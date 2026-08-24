<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaAnticipoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaAnticipo;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\Advance\Advance;
use App\Models\Advance\AdvanceApplication;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Tier 0 — conexión Adelantos↔Reservas (hallazgo de auditoría del módulo
// Adelantos, 2026-08-21): `reserva_anticipos` existía desde Sesión 8b sin
// ningún controller que la usara, y ReservaFacturacionController::store()
// nunca descontaba anticipos ya pagados hacia la reserva. Mismo patrón de
// fixture que ReservaFacturacionTest — Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class ReservaAnticipoTest extends TestCase
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

        $this->setUpTenantFixture(true);
    }

    protected function tearDown(): void
    {
        app(\Stancl\Tenancy\Tenancy::class)->tenant = null;
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function setUpTenantFixture(?bool $facturacionHabilitada): void
    {
        $tenant = new \App\Models\Tenant();
        $tenant->id = 'test-tenant-' . uniqid();
        $tenant->facturacion_habilitada = $facturacionHabilitada;

        app(\Stancl\Tenancy\Tenancy::class)->tenant = $tenant;
    }

    // Usuario con permisos de emisión + sucursal con series + sesión de
    // caja abierta — necesario porque ReservaAnticipoController::store()
    // llama AdvanceController::store() por dentro, que exige caja abierta
    // (Módulo Caja — Fase 6, guard incondicional).
    private function usuarioCompleto(): User
    {
        $branch = Branch::create(['name' => 'Sede Test Anticipos', 'is_active' => true]);
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
        foreach (['emitir_factura', 'emitir_boleta', 'agencia.reservas'] as $permisoNombre) {
            $permission = Permission::firstOrCreate(['name' => $permisoNombre, 'guard_name' => 'api']);
            $role->givePermissionTo($permission);
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

    // Reserva mínima: 1 pasajero, 1 ítem manual (sin cadena proveedor/
    // servicio, mismo atajo que ReservaFacturacionTest::
    // test_preparar_factura_sin_items_auto_incluidos_devuelve_200_no_422).
    private function crearReservaSimple(float $precioConvertido = 118.00): Reserva
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '77889900', 'full_name' => 'Cliente Test Anticipos',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0900-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $cp = DB::table('cotizacion_pasajeros')->insertGetId([
            'cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Paquete completo', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => $precioConvertido * 0.7,
            'precio_venta_snapshot' => $precioConvertido, 'precio_convertido' => $precioConvertido,
            'pax_incluidos' => [$cp],
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return $reserva->fresh();
    }

    private function pasajero(Reserva $reserva): ReservaPasajero
    {
        return ReservaPasajero::where('reserva_id', $reserva->id)->firstOrFail();
    }

    // Un anticipo solo puede netear contra una factura del MISMO cliente
    // que lo pagó (AdvanceApplicationService lo exige, Tier 1) — para los
    // tests que esperan que el neteo funcione, la factura debe ir a
    // nombre del cliente de la propia cotización, no de un cliente al
    // azar (eso sí es válido en la "Facturación múltiple" real, pero ahí
    // el anticipo de otro cliente correctamente NO se aplica).
    private function clienteDeLaReserva(Reserva $reserva): Client
    {
        return $reserva->alternativa->cotizacion->cliente;
    }

    // AdvanceApplicationService exige que el comprobante propio del
    // adelanto ya haya sido enviado y aceptado por SUNAT (n_operacion
    // poblado) antes de poder aplicarlo — mismo guard del Tier 1. Acá se
    // simula sin pegarle a Greenter real, mismo criterio que
    // ReservarCorrelativoTest ("el factory por default simula una venta
    // YA aceptada").
    private function simularAdelantoAceptado(Advance $advance): void
    {
        $advance->sale->update(['n_operacion' => 'F001-' . str_pad((string) $advance->sale_id, 8, '0', STR_PAD_LEFT)]);
    }

    // ── ReservaAnticipoController::store() ───────────────────────────────
    public function test_store_crea_advance_y_lo_etiqueta_a_la_reserva(): void
    {
        $this->usuarioCompleto();
        $reserva = $this->crearReservaSimple();
        $clienteEsperado = $reserva->alternativa->cotizacion->cliente_id;

        // client_id enviado en el payload debe ser IGNORADO — se deriva de
        // la reserva, nunca del payload (blindaje de moneda/cliente).
        $response = app(ReservaAnticipoController::class)->store(new Request([
            'monto' => 50.00,
            'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
            'client_id' => 999999,
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));
        $this->assertSame(1, Advance::count());
        $this->assertSame(1, ReservaAnticipo::count());

        $advance = Advance::first();
        $this->assertSame($clienteEsperado, $advance->client_id);
        $this->assertSame(50.0, (float) $advance->amount);
        $this->assertSame('PEN', $advance->currency);

        $reservaAnticipo = ReservaAnticipo::first();
        $this->assertSame($reserva->id, $reservaAnticipo->reserva_id);
        $this->assertSame($advance->id, $reservaAnticipo->advance_id);
        $this->assertSame(50.0, (float) $reservaAnticipo->monto_asignado);
    }

    public function test_store_rechaza_reserva_no_activa(): void
    {
        $this->usuarioCompleto();
        $reserva = $this->crearReservaSimple();
        $reserva->update(['estado' => 'cancelada']);

        $response = app(ReservaAnticipoController::class)->store(new Request([
            'monto' => 50.00,
            'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Advance::count());
    }

    public function test_destroy_bloqueado_si_el_advance_ya_se_aplico_a_una_venta(): void
    {
        $this->usuarioCompleto();
        $reserva = $this->crearReservaSimple();

        $response = app(ReservaAnticipoController::class)->store(new Request([
            'monto' => 50.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $reserva->id);
        $reservaAnticipo = ReservaAnticipo::first();

        // Simula que el adelanto ya se consumió en una venta real.
        $ventaCualquiera = Sale::factory()->create(['client_id' => $reservaAnticipo->advance->client_id]);
        AdvanceApplication::create([
            'advance_id' => $reservaAnticipo->advance_id,
            'sale_id' => $ventaCualquiera->id,
            'amount_applied' => 10.00,
        ]);

        $destroyResponse = app(ReservaAnticipoController::class)->destroy((string) $reservaAnticipo->id);
        $this->assertSame(422, $destroyResponse->getStatusCode());
        $this->assertSame(1, ReservaAnticipo::count(), 'no debió borrarse');
    }

    public function test_destroy_permitido_si_nunca_se_aplico(): void
    {
        $this->usuarioCompleto();
        $reserva = $this->crearReservaSimple();

        app(ReservaAnticipoController::class)->store(new Request([
            'monto' => 50.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $reserva->id);
        $reservaAnticipo = ReservaAnticipo::first();

        $response = app(ReservaAnticipoController::class)->destroy((string) $reservaAnticipo->id);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, ReservaAnticipo::count());
        // El Advance en sí sigue existiendo — solo se quitó el tag.
        $this->assertSame(1, Advance::count());
    }

    // ── Netear anticipos al facturar ─────────────────────────────────────
    public function test_facturar_simple_aplica_anticipos_disponibles_sin_pasarse(): void
    {
        $usuario = $this->usuarioCompleto();
        $reserva = $this->crearReservaSimple();
        $pasajero = $this->pasajero($reserva);
        $cliente = $this->clienteDeLaReserva($reserva);

        // Total real de la reserva (vía preview) antes de anticipar nada.
        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$pasajero->id],
        ]), (string) $reserva->id);
        $totalFactura = (float) $preview->getData(true)['total'];
        $this->assertGreaterThan(0, $totalFactura);

        // Anticipo a propósito MÁS GRANDE que el total, para probar que no
        // se pasa (cap greedy, deja resto disponible).
        $montoAnticipo = $totalFactura + 100;
        app(ReservaAnticipoController::class)->store(new Request([
            'monto' => $montoAnticipo, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $reserva->id);
        $advance = Advance::first();
        $this->simularAdelantoAceptado($advance);

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$pasajero->id],
            'client_id' => $cliente->id,
            // Boleta: clienteDeLaReserva() usa el cliente DNI de
            // crearReservaSimple() (el anticipo solo netea contra el
            // mismo cliente que lo pagó — no puede cambiarse a un
            // cliente RUC distinto sin romper ese neteo).
            'tipo_comprobante_codigo' => '03',
        ]), (string) $reserva->id);
        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));

        $venta = Sale::find($body['sale_id']);
        $this->assertSame(0.0, (float) $venta->debt, 'debe quedar totalmente cubierta por el anticipo');
        $this->assertSame(3, $venta->state_payment, 'pagado, no pendiente');
        $this->assertSame(0.0, (float) $venta->total, 'total neteado a cero');

        $advance->refresh();
        $this->assertSame($totalFactura, (float) $advance->applied_amount, 'aplicó exactamente lo que cubría el total, no el anticipo completo');
        $this->assertSame(round($montoAnticipo - $totalFactura, 2), $advance->availableBalance(), 'el resto queda disponible para otra sub-factura');
        $this->assertSame(1, AdvanceApplication::count());
    }

    public function test_facturar_sin_anticipos_se_comporta_igual_que_antes(): void
    {
        $this->usuarioCompleto();
        $reserva = $this->crearReservaSimple();
        $pasajero = $this->pasajero($reserva);
        $cliente = Client::factory()->empresa()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$pasajero->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);
        $body = $response->getData(true);
        $this->assertSame(200, $body['code']);

        $venta = Sale::find($body['sale_id']);
        $this->assertGreaterThan(0, (float) $venta->debt, 'sin anticipos, la deuda sigue siendo el total completo');
        $this->assertSame(1, $venta->state_payment, 'pendiente, sin cambios de comportamiento previo');
        $this->assertSame(0, AdvanceApplication::count());
    }

    public function test_facturar_especial_con_advance_applications_explicito(): void
    {
        $this->usuarioCompleto();
        $reserva = $this->crearReservaSimple();
        $pasajero = $this->pasajero($reserva);
        $cliente = $this->clienteDeLaReserva($reserva);

        app(ReservaAnticipoController::class)->store(new Request([
            'monto' => 200.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $reserva->id);
        $advance = Advance::first();
        $this->simularAdelantoAceptado($advance);

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$pasajero->id],
            'client_id' => $cliente->id,
            // Boleta: clienteDeLaReserva() (ver comentario de la clase).
            'tipo_comprobante_codigo' => '03',
            'advance_applications' => [['advance_id' => $advance->id, 'amount' => 30.00]],
        ]), (string) $reserva->id);
        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));

        $advance->refresh();
        $this->assertSame(30.0, (float) $advance->applied_amount, 'solo lo indicado a mano, no el disponible completo');

        $venta = Sale::find($body['sale_id']);
        $this->assertSame(30.0, (float) $venta->paid_out);
    }

    public function test_facturar_rechaza_advance_id_no_asociado_a_la_reserva(): void
    {
        $this->usuarioCompleto();
        $reservaA = $this->crearReservaSimple();
        $reservaB = $this->crearReservaSimple();
        $pasajeroA = $this->pasajero($reservaA);
        $cliente = $this->clienteDeLaReserva($reservaA);

        // Anticipo de la reserva B — no debe poder aplicarse facturando A.
        app(ReservaAnticipoController::class)->store(new Request([
            'monto' => 200.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $reservaB->id);
        $advanceDeB = Advance::first();

        // El guard corre DENTRO de la transacción de store() — se relanza
        // como HttpException (catch(HttpException) { throw $e; }, mismo
        // patrón que el resto del método), no vuelve como Response.
        try {
            app(ReservaFacturacionController::class)->store(new Request([
                'pasajero_ids' => [$pasajeroA->id],
                'client_id' => $cliente->id,
                // Boleta: clienteDeLaReserva() (ver comentario de la clase).
                'tipo_comprobante_codigo' => '03',
                'advance_applications' => [['advance_id' => $advanceDeB->id, 'amount' => 30.00]],
            ]), (string) $reservaA->id);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString((string) $advanceDeB->id, $e->getMessage());
        }

        $this->assertSame(0, Sale::where('type', '!=', 'advance')->count(), 'no debe crear nada a medias');
    }

    // ── prepararFactura() — preview de anticipos ─────────────────────────
    public function test_preparar_factura_incluye_anticipos_disponibles(): void
    {
        $this->usuarioCompleto();
        $reserva = $this->crearReservaSimple();
        $pasajero = $this->pasajero($reserva);

        app(ReservaAnticipoController::class)->store(new Request([
            'monto' => 75.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $reserva->id);

        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$pasajero->id],
        ]), (string) $reserva->id);
        $body = $preview->getData(true);

        $this->assertCount(1, $body['anticipos_disponibles']);
        $this->assertSame(75.0, (float) $body['anticipos_disponibles'][0]['disponible']);
        $this->assertSame('PEN', $body['anticipos_disponibles'][0]['moneda']);
    }
}
