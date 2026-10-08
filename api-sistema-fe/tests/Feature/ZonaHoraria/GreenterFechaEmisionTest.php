<?php

declare(strict_types=1);

namespace Tests\Feature\ZonaHoraria;

use App\Http\Controllers\Greenter\GreenterService;
use App\Models\Company;
use App\Models\Sale\Sale;
use Carbon\CarbonImmutable;
use Greenter\Xml\Builder\InvoiceBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * La fecha de emisión que va al XML (IssueDate) es el día de Perú, también de 19:00 a 24:00,
 * cuando UTC ya está en el día siguiente, y sin importar la zona por defecto de PHP.
 */
final class GreenterFechaEmisionTest extends CreditosTestCase
{
    /** @return array<string, array{string}> */
    public static function zonasDePhp(): array
    {
        return ['php en UTC' => ['UTC'], 'php en Lima (mutadores)' => ['America/Lima']];
    }

    #[DataProvider('zonasDePhp')]
    public function test_issue_date_del_xml_es_el_dia_de_peru_de_noche(string $zonaPhp): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 04:30:00', 'UTC'));   // 23:30 del 08-oct en Perú
        $original = date_default_timezone_get();
        date_default_timezone_set($zonaPhp);
        try {
            $venta = Sale::factory()->create(['type_payment' => 1]);
            $empresa = Company::create([
                'razon_social' => 'Empresa de Prueba SAC',
                'razon_social_comercial' => 'Empresa de Prueba',
                'n_document' => '20123456789',
            ]);
            $invoice = (new GreenterService())->getInvoice([
                'tipo_operacion' => '0101', 'tipo_doc' => '01', 'serie' => 'F001', 'correlativo' => '1',
                'tipo_moneda' => 'PEN', 'mto_imp_venta' => 118.0, 'isc' => 0, 'mto_oper_gravadas' => 100.0,
                'mto_oper_exoneradas' => 0, 'mto_oper_inafectas' => 0, 'mto_oper_gratuitas' => 0,
                'mto_oper_exportacion' => null, 'mto_base_ivap' => null, 'mto_ivap' => null, 'mto_igv' => 18.0,
                'mto_igv_gratuitas' => 0, 'icbper' => 0, 'total_impuestos' => 18.0, 'valor_venta' => 100.0,
                'sub_total' => 118.0, 'redondeo' => 0, 'legends' => [],
            ], $empresa, $venta);

            $xml = (new InvoiceBuilder())->build($invoice);

            $this->assertStringContainsString('<cbc:IssueDate>2026-10-08</cbc:IssueDate>', $xml);
            $this->assertStringContainsString('<cbc:IssueTime>23:30:00</cbc:IssueTime>', $xml);
        } finally {
            date_default_timezone_set($original);
        }
    }
}
