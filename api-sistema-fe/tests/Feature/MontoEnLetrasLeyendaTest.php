<?php

namespace Tests\Feature;

use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Controllers\Sale\FacturacionElectronicaController;
use App\Models\Company;
use App\Models\Sale\Sale;
use App\Services\MontoEnLetras;
use Greenter\Xml\Builder\InvoiceBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

// T-038 (H27): la leyenda 1000 (monto en letras) del XML de una venta decía
// "SOLES" siempre, también en una factura en dólares, y no coincidía con su
// propio PDF. Ahora la venta, la nota y el PDF usan MontoEnLetras.
class MontoEnLetrasLeyendaTest extends TestCase
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

        DB::table('roles')->insertOrIgnore(['id' => 1, 'name' => 'rol-leyenda', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_el_nombre_de_la_moneda_sale_de_la_moneda(): void
    {
        $this->assertStringEndsWith('CON 50/100 DOLARES', MontoEnLetras::comprobante(152.50, 'USD'));
        $this->assertStringEndsWith('CON 50/100 SOLES', MontoEnLetras::comprobante(152.50, 'PEN'));
        $this->assertStringEndsWith('SOLES', MontoEnLetras::comprobante(10, null), 'venta antigua sin moneda');
    }

    public function test_una_moneda_desconocida_no_se_adivina(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MontoEnLetras::comprobante(10, 'EUR');
    }

    public function test_el_xml_de_una_factura_en_dolares_dice_dolares(): void
    {
        foreach (['USD' => 'DOLARES', 'PEN' => 'SOLES'] as $moneda => $palabra) {
            $venta = Sale::factory()->create(['currency' => $moneda, 'type_payment' => 1, 'total' => 236, 'subtotal' => 200, 'igv' => 36]);

            $datos = app(FacturacionElectronicaController::class)->armarLeyendas([
                'tipo_operacion' => '0101', 'tipo_doc' => '01', 'serie' => 'F001', 'correlativo' => '1',
                'tipo_moneda' => $moneda, 'mto_imp_venta' => 236.0, 'isc' => 0,
                'mto_oper_gravadas' => 200.0, 'mto_oper_exoneradas' => 0, 'mto_oper_inafectas' => 0,
                'mto_oper_gratuitas' => 0, 'mto_oper_exportacion' => null, 'mto_base_ivap' => null,
                'mto_ivap' => null, 'mto_igv' => 36.0, 'mto_igv_gratuitas' => 0, 'icbper' => 0,
                'total_impuestos' => 36.0, 'valor_venta' => 200.0, 'sub_total' => 236.0, 'redondeo' => 0,
            ], $venta->currency);

            $empresa = Company::first() ?? Company::create(['razon_social' => 'Empresa de Prueba SAC', 'razon_social_comercial' => 'Empresa de Prueba', 'n_document' => '20123456789']);
            $invoice = (new GreenterService())->getInvoice($datos, $empresa, $venta);
            $xml = (new InvoiceBuilder())->build($invoice);

            $this->assertStringContainsString("DOSCIENTOS TREINTA Y SEIS CON 00/100 {$palabra}", $xml, $moneda);
            $this->assertSame($venta->resumenImpresion()['monto_en_letras'], $datos['legends'][0]['value'], "XML y PDF iguales ($moneda)");
        }
    }
}
