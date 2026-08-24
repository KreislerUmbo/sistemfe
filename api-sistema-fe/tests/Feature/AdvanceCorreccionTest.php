<?php

namespace Tests\Feature;

use App\Http\Controllers\Advance\AdvanceController;
use App\Models\Advance\Advance;
use App\Models\Advance\AdvanceApplication;
use App\Models\Cash\Branch;
use App\Models\Cash\CashRegister;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Models\Sale\Note;
use App\Models\Sale\NoteSerie;
use App\Models\Sale\Sale;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Tier 1 + Tier 2 del módulo Adelantos (2026-08-24): selector de
// tratamiento tributario al crear (antes gravado 18% siempre, sin
// selector) + corrección post-SUNAT (anula con NC motivo 01 + reemite,
// nunca edita un comprobante ya aceptado). Mismo patrón que el resto de la
// suite del módulo: Postgres real (sistemafe_test_migrations), transacción
// por test revertida en tearDown().
class AdvanceCorreccionTest extends TestCase
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

        // SerieNotaResolver (mecanismo viejo de NC/ND, sin relación con el
        // módulo de series de comprobantes) — necesario para que
        // NotaElectronicaController::store() resuelva la serie de la NC de
        // corrección. tipo_doc_afectado='01' porque los adelantos de este
        // archivo siempre usan factura (serie F001). firstOrCreate(): la
        // fila ya existe como fixture base de sistemafe_test_migrations
        // (id=1, desde 2026-07-19), no es exclusiva de este archivo.
        NoteSerie::firstOrCreate(['tipo_doc' => '07', 'tipo_doc_afectado' => '01'], ['serie' => 'FC01']);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function usuarioConSesionCaja(int $branchId): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);
        $role = Role::create(['name' => 'rol-correccion-' . uniqid(), 'guard_name' => 'api']);
        $user->assignRole($role);
        $user->role_id = $role->id;
        $user->save();

        $cashRegister = CashRegister::create(['branch_id' => $branchId, 'name' => 'Caja Test', 'is_active' => true]);
        CashSession::create([
            'cash_register_id' => $cashRegister->id, 'opened_by' => $user->id,
            'opening_amount' => 0, 'opened_at' => now(), 'status' => 'open',
        ]);

        Auth::guard('api')->setUser($user->fresh());

        return $user->fresh();
    }

    private function branchConSerieFactura(): Branch
    {
        $branch = Branch::create(['name' => 'Sede Test Corrección', 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $branch->id, 'tipo_comprobante_codigo' => '01', 'moneda' => 'PEN',
            'serie' => 'F001', 'correlativo_actual' => 0, 'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'), 'activo' => true,
        ]);

        return $branch;
    }

    private function registrarAdelanto(Client $cliente, string $tipAfeIgv = '10', float $amount = 118.00): array
    {
        $request = new Request([
            'client_id' => $cliente->id,
            'amount' => $amount,
            'currency' => 'PEN',
            'payment_method' => 'EFECTIVO',
            'tip_afe_igv' => $tipAfeIgv,
        ]);

        $response = app(AdvanceController::class)->store($request);

        return json_decode($response->getContent(), true);
    }

    // Simula "ya aceptado por SUNAT" sin pegarle a Greenter real — mismo
    // criterio que ReservarCorrelativoTest/AdvanceIntegridadTest.
    private function marcarAceptadoPorSunat(int $saleId): void
    {
        // correlativo también hace falta: NotaElectronicaController::store()
        // lo copia a notes.correlativo_afectado (NOT NULL) — una venta real
        // ya lo tiene poblado desde reservarCorrelativo() al enviarse.
        Sale::where('id', $saleId)->update([
            'correlativo' => $saleId,
            'n_operacion' => 'F001-' . str_pad((string) $saleId, 8, '0', STR_PAD_LEFT),
            'xml' => '<xml>fake</xml>',
            'cdr' => 'fake-cdr-content',
        ]);
    }

    // ── Tier 1: selector de tratamiento tributario ───────────────────────
    public function test_store_gravado_calcula_igv_18_por_ciento(): void
    {
        $branch = $this->branchConSerieFactura();
        $this->usuarioConSesionCaja($branch->id);
        $cliente = Client::factory()->empresa()->create();

        $body = $this->registrarAdelanto($cliente, '10', 118.00);
        $venta = Sale::find($body['sale_id']);

        $this->assertSame(100.0, (float) $venta->subtotal);
        $this->assertSame(18.0, (float) $venta->igv);
        $this->assertSame(100.0, (float) $venta->mto_oper_gravadas);
        $this->assertSame(0.0, (float) $venta->mto_oper_exoneradas);
        $this->assertSame(0.0, (float) $venta->mto_oper_inafectas);
        $this->assertSame('10', $venta->sale_details()->first()->tip_afe_igv);
    }

    public function test_store_exonerado_sin_igv(): void
    {
        $branch = $this->branchConSerieFactura();
        $this->usuarioConSesionCaja($branch->id);
        $cliente = Client::factory()->empresa()->create();

        $body = $this->registrarAdelanto($cliente, '20', 100.00);
        $venta = Sale::find($body['sale_id']);

        $this->assertSame(100.0, (float) $venta->subtotal);
        $this->assertSame(0.0, (float) $venta->igv);
        $this->assertSame(0.0, (float) $venta->mto_oper_gravadas);
        $this->assertSame(100.0, (float) $venta->mto_oper_exoneradas);
        $this->assertSame(0.0, (float) $venta->mto_oper_inafectas);
        $this->assertSame('20', $venta->sale_details()->first()->tip_afe_igv);
    }

    public function test_store_inafecto_sin_igv(): void
    {
        $branch = $this->branchConSerieFactura();
        $this->usuarioConSesionCaja($branch->id);
        $cliente = Client::factory()->empresa()->create();

        $body = $this->registrarAdelanto($cliente, '30', 100.00);
        $venta = Sale::find($body['sale_id']);

        $this->assertSame(0.0, (float) $venta->igv);
        $this->assertSame(0.0, (float) $venta->mto_oper_gravadas);
        $this->assertSame(0.0, (float) $venta->mto_oper_exoneradas);
        $this->assertSame(100.0, (float) $venta->mto_oper_inafectas);
        $this->assertSame('30', $venta->sale_details()->first()->tip_afe_igv);
    }

    public function test_store_rechaza_tip_afe_igv_invalido(): void
    {
        $branch = $this->branchConSerieFactura();
        $this->usuarioConSesionCaja($branch->id);
        $cliente = Client::factory()->empresa()->create();

        try {
            $this->registrarAdelanto($cliente, '99', 100.00);
            $this->fail('Se esperaba error de validación, no se lanzó ninguno.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('tip_afe_igv', $e->errors());
        }
    }

    // ── Tier 2: corregir() ────────────────────────────────────────────────
    public function test_corregir_rechaza_si_no_esta_aceptado_por_sunat(): void
    {
        $branch = $this->branchConSerieFactura();
        $this->usuarioConSesionCaja($branch->id);
        $cliente = Client::factory()->empresa()->create();

        $body = $this->registrarAdelanto($cliente, '10');
        // Sin marcarAceptadoPorSunat() — xml/cdr siguen null.

        try {
            app(AdvanceController::class)->corregir(new Request([
                'tip_afe_igv' => '20',
                'motivo_correccion' => 'El contador observó que debía salir exonerado.',
            ]), (string) $body['advance_id']);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_corregir_anula_y_reemite_con_el_tratamiento_correcto(): void
    {
        $branch = $this->branchConSerieFactura();
        $this->usuarioConSesionCaja($branch->id);
        $cliente = Client::factory()->empresa()->create();

        $body = $this->registrarAdelanto($cliente, '10', 118.00);
        $ventaOriginalId = $body['sale_id'];
        $this->marcarAceptadoPorSunat($ventaOriginalId);

        $response = app(AdvanceController::class)->corregir(new Request([
            'tip_afe_igv' => '20',
            'motivo_correccion' => 'El contador observó que debía salir exonerado por Ley 27037.',
        ]), (string) $body['advance_id']);
        $respBody = $response->getData(true);
        $this->assertSame(200, $respBody['code'], json_encode($respBody));

        $adelanto = Advance::find($body['advance_id']);

        // Mismo Advance.id, sale_id apunta al comprobante NUEVO.
        $this->assertNotSame($ventaOriginalId, $adelanto->sale_id);
        $this->assertSame($ventaOriginalId, $adelanto->corrected_from_sale_id);
        $this->assertStringContainsString('exonerado', $adelanto->correction_reason);
        $this->assertNotNull($adelanto->corrected_at);
        $this->assertNotNull($adelanto->corrected_by);

        // El comprobante nuevo tiene el tratamiento correcto. El amount
        // (118) no cambia en una corrección — solo la clasificación — así
        // que con 0% IGV el subtotal absorbe el monto completo.
        $ventaNueva = $adelanto->sale;
        $this->assertSame(0.0, (float) $ventaNueva->igv);
        $this->assertSame(118.0, (float) $ventaNueva->mto_oper_exoneradas);
        $this->assertSame('20', $ventaNueva->sale_details()->first()->tip_afe_igv);

        // El comprobante viejo quedó anulado — una NC motivo 01 real, total,
        // contra él.
        $nota = Note::where('sale_id', $ventaOriginalId)->first();
        $this->assertNotNull($nota, 'Debió crearse una Nota de Crédito sobre el comprobante viejo.');
        $this->assertSame('07', $nota->tipo_doc);
        $this->assertSame('01', $nota->cod_motivo);
        $this->assertSame('total', $nota->tipo_afectacion);
    }

    public function test_corregir_no_toca_applications_ya_hechas(): void
    {
        $branch = $this->branchConSerieFactura();
        $this->usuarioConSesionCaja($branch->id);
        $cliente = Client::factory()->empresa()->create();

        $body = $this->registrarAdelanto($cliente, '10', 118.00);
        $this->marcarAceptadoPorSunat($body['sale_id']);

        $ventaDestino = Sale::factory()->create(['client_id' => $cliente->id]);
        AdvanceApplication::create([
            'advance_id' => $body['advance_id'],
            'sale_id' => $ventaDestino->id,
            'amount_applied' => 50.00,
        ]);
        Advance::where('id', $body['advance_id'])->update(['applied_amount' => 50.00]);

        app(AdvanceController::class)->corregir(new Request([
            'tip_afe_igv' => '20',
            'motivo_correccion' => 'Corrección de prueba con adelanto ya aplicado.',
        ]), (string) $body['advance_id']);

        $adelanto = Advance::find($body['advance_id']);
        $this->assertSame(50.0, (float) $adelanto->applied_amount, 'la corrección no debe tocar lo ya aplicado');
        $this->assertSame(1, AdvanceApplication::where('advance_id', $body['advance_id'])->count());
        $this->assertSame(1, $adelanto->applications()->count());
        $this->assertSame($ventaDestino->id, $adelanto->applications()->first()->sale_id, 'la aplicación sigue apuntando a la venta destino original, no a la corregida');
    }

    // ── Tier 3: referencia de pago (SalePayment.comments) ────────────────
    public function test_store_guarda_referencia_de_pago_para_medios_no_efectivo(): void
    {
        PaymentMethod::firstOrCreate(
            ['code' => 'TRANSFERENCIA'],
            ['name' => 'Transferencia', 'is_active' => true, 'sort_order' => 2, 'affects_cash_count' => false]
        );

        $branch = $this->branchConSerieFactura();
        $this->usuarioConSesionCaja($branch->id);
        $cliente = Client::factory()->empresa()->create();

        $response = app(AdvanceController::class)->store(new Request([
            'client_id' => $cliente->id,
            'amount' => 118.00,
            'currency' => 'PEN',
            'payment_method' => 'TRANSFERENCIA',
            'payment_reference' => 'BCP op. 000123456',
            'tip_afe_igv' => '10',
        ]));
        $body = $response->getData(true);

        $pago = \App\Models\Sale\SalePayment::where('sale_id', $body['sale_id'])->first();
        $this->assertSame('BCP op. 000123456', $pago->comments);
    }
}
