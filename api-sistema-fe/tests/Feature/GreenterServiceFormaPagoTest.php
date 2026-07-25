<?php

namespace Tests\Feature;

use App\Http\Controllers\Greenter\GreenterService;
use App\Models\Company;
use App\Models\Credit\Installment;
use App\Models\Sale\Sale;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\FormaPagos\FormaPagoCredito;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Bloque D de la matriz tributaria (FormaPago SUNAT: contado vs crédito,
// Módulo Amortizaciones Fase 8.0). Corre contra sistemafe_test_migrations
// (Postgres real, recreada limpia en esta conversación) — nunca contra
// sv_facturacion ni ningún tenant real. La conexión 'pgsql' se redirige a
// esa base solo para esta clase; cada test corre en su propia transacción,
// revertida en tearDown() — no queda ninguna fila de prueba persistida.
class GreenterServiceFormaPagoTest extends TestCase
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
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();

        // users.role_id tiene default(1) a nivel de Postgres (users_role_id_foreign) —
        // esta base recién migrada no trae seeds, así que sin esta fila cualquier
        // User::factory() (usado internamente por Sale::factory() para user_id) revienta
        // con FK violation antes de llegar a lo que este test realmente quiere probar.
        DB::table('roles')->insert([
            'id' => 1,
            'name' => 'test-role',
            'guard_name' => 'api',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function empresa(): Company
    {
        return Company::create([
            'razon_social' => 'Empresa de Prueba SAC',
            'razon_social_comercial' => 'Empresa de Prueba',
            'n_document' => '20123456789',
        ]);
    }

    private function datosComprobante(Sale $venta): array
    {
        return [
            'tipo_operacion' => '0101',
            'tipo_doc' => '01',
            'serie' => $venta->serie,
            'correlativo' => (string) $venta->correlativo,
            'tipo_moneda' => $venta->currency,
            'mto_imp_venta' => (float) $venta->total,
            'isc' => 0,
            'mto_oper_gravadas' => (float) $venta->subtotal,
            'mto_oper_exoneradas' => 0,
            'mto_oper_inafectas' => 0,
            'mto_oper_gratuitas' => 0,
            'mto_oper_exportacion' => null,
            'mto_base_ivap' => null,
            'mto_ivap' => null,
            'mto_igv' => (float) $venta->igv,
            'mto_igv_gratuitas' => 0,
            'icbper' => 0,
            'total_impuestos' => (float) $venta->igv,
            'valor_venta' => (float) $venta->subtotal,
            'sub_total' => (float) $venta->total,
            'redondeo' => 0,
            'legends' => [],
        ];
    }

    // D1: contado → FormaPagoContado().
    public function test_d1_contado_usa_forma_pago_contado(): void
    {
        $venta = Sale::factory()->create(['type_payment' => 1]);

        $invoice = (new GreenterService())->getInvoice(
            $this->datosComprobante($venta),
            $this->empresa(),
            $venta
        );

        $this->assertInstanceOf(FormaPagoContado::class, $invoice->getFormaPago());
    }

    // D2: crédito, 1 cuota → FormaPagoCredito() + Cuota[] con 1 elemento.
    public function test_d2_credito_una_cuota_usa_forma_pago_credito(): void
    {
        $venta = Sale::factory()->cuotasFijas()->create(['type_payment' => 2]);
        Installment::factory()->for($venta, 'sale')->create([
            'numero_cuota' => 1,
            'monto_programado' => 100.00,
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
            'estado' => 'pendiente',
        ]);

        $invoice = (new GreenterService())->getInvoice(
            $this->datosComprobante($venta),
            $this->empresa(),
            $venta
        );

        $this->assertInstanceOf(FormaPagoCredito::class, $invoice->getFormaPago());
        $this->assertCount(1, $invoice->getCuotas());
    }

    // D3: crédito, 3 cuotas → Cuota[] con 3 elementos, montos y fechas correctos.
    public function test_d3_credito_tres_cuotas_montos_y_fechas_correctos(): void
    {
        $venta = Sale::factory()->cuotasFijas()->create(['type_payment' => 2]);

        $montos = [100.00, 150.50, 200.25];
        $fechas = [];
        foreach ($montos as $i => $monto) {
            $fecha = now()->addDays(30 * ($i + 1))->toDateString();
            $fechas[] = $fecha;
            Installment::factory()->for($venta, 'sale')->create([
                'numero_cuota' => $i + 1,
                'monto_programado' => $monto,
                'fecha_vencimiento' => $fecha,
                'estado' => 'pendiente',
            ]);
        }

        $invoice = (new GreenterService())->getInvoice(
            $this->datosComprobante($venta),
            $this->empresa(),
            $venta
        );

        $this->assertInstanceOf(FormaPagoCredito::class, $invoice->getFormaPago());
        $cuotas = $invoice->getCuotas();
        $this->assertCount(3, $cuotas);

        foreach ($cuotas as $i => $cuota) {
            $this->assertEquals($montos[$i], (float) $cuota->getMonto());
            $this->assertEquals($fechas[$i], $cuota->getFechaPago()->format('Y-m-d'));
        }
    }

    // D4: crédito, installments vacío/anulado → 422 explícito, nunca cae a FormaPagoContado().
    public function test_d4_credito_sin_installments_activas_lanza_422(): void
    {
        // credit_type='libre': nunca tiene cronograma (installments) por diseño.
        $venta = Sale::factory()->libre()->create(['type_payment' => 2]);

        try {
            (new GreenterService())->getInvoice(
                $this->datosComprobante($venta),
                $this->empresa(),
                $venta
            );
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('cronograma de cuotas vigente', $e->getMessage());
        }
    }

    // D4b: mismo caso, pero con credit_type='cuotas_fijas' y todas las cuotas anuladas
    // (en vez de 0 filas) — cuotasActivasParaCredito() debe excluirlas igual.
    public function test_d4b_credito_con_todas_las_cuotas_anuladas_lanza_422(): void
    {
        $venta = Sale::factory()->cuotasFijas()->create(['type_payment' => 2]);
        Installment::factory()->for($venta, 'sale')->anulada()->create([
            'numero_cuota' => 1,
            'monto_programado' => 100.00,
        ]);

        try {
            (new GreenterService())->getInvoice(
                $this->datosComprobante($venta),
                $this->empresa(),
                $venta
            );
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }
}
