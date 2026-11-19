<?php

namespace Tests\Feature;

use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Controllers\Sale\NotaElectronicaController;
use App\Http\Controllers\Sale\SaleController;
use App\Http\Resources\Sale\SaleResource;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\Company;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use App\Models\Sale\Note;
use App\Models\Sale\NoteSerie;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Creditos\CreditosTestCase;

// T-108: la descripción editada de una línea REEMPLAZA al nombre del catálogo en XML, PDF A4,
// ticket y notas. Postgres real, transacción por test (base CreditosTestCase).
class DescripcionLineaVentaTest extends CreditosTestCase
{
    private const TEXTO = "Antivirus instalado en equipo SALA-01\nVence el 18/11/2027";

    private Product $producto;

    private function emisor(): User
    {
        $branch = Branch::create(['name' => 'Sede T-108 ' . uniqid(), 'is_active' => true]);
        foreach ([['01', 'F001'], ['NV', 'NV01']] as [$tipo, $serie]) {
            SerieComprobante::create([
                'branch_id' => $branch->id, 'tipo_comprobante_codigo' => $tipo, 'moneda' => 'PEN', 'serie' => $serie,
                'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => '2026-01-01', 'activo' => true,
            ]);
        }
        NoteSerie::firstOrCreate(['tipo_doc' => '07', 'tipo_doc_afectado' => '01'], ['serie' => 'FC01']);
        $usuario = $this->usuario(['emitir_nota_venta']);
        $usuario->update(['branch_id' => $branch->id]);
        $usuario = $usuario->fresh();
        $this->abrirCaja($usuario);
        Auth::guard('api')->setUser($usuario);

        return $usuario;
    }

    private function empresa(): Client
    {
        return Client::factory()->empresa()->create(['full_name' => 'Transportes Selva SAC', 'n_document' => '20111111111']);
    }

    private function payload(Client $cliente, array $linea = [], array $extra = []): array
    {
        $this->producto ??= Product::create([
            'title' => 'Antivirus ESET NOD32 1 PC 1 año', 'sku' => 'SKU-T108-' . uniqid(),
            'categorie_id' => Categorie::create(['title' => 'Cat T108', 'state' => 1])->id,
            'price_general' => 50, 'price_company' => 50, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 50,
            'is_discount' => false, 'disponiblidad' => 1, 'include_igv' => false, 'is_icbper' => false,
            'is_ivap' => false, 'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
        ]);

        return [
            'date' => '2026-01-01', 'tipo_comprobante_codigo' => 'NV', 'n_transaction' => '00000001',
            'client_id' => $cliente->id, 'type_client' => 1, 'cod_tipo_doc_cliente' => '6',
            'currency' => 'PEN', 'is_exportacion' => 0, 'destino' => 'nacional', 'type_payment' => 1,
            'subtotal' => 100.00, 'igv' => 18.00, 'total' => 118.00, 'discount' => 0, 'discount_global' => 0,
            'retencion_igv' => 0, 'state_payment' => 3, 'debt' => 0, 'paid_out' => 118,
            'sale_details' => [$linea + [
                'product' => ['id' => $this->producto->id, 'categorie_id' => $this->producto->categorie_id],
                'unidad_medida' => 'NIU', 'quantity' => 2, 'price_base' => 50, 'price_final' => 59, 'discount' => 0,
                'subtotal' => 100, 'mto_valor_venta' => 100, 'mto_base_igv' => 100, 'porcentaje_igv' => 18,
                'igv' => 18, 'total_impuestos' => 18, 'tip_afe_igv' => '10',
            ]],
            'payments' => [['method_payment' => 'EFECTIVO', 'amount' => 118.00, 'date_payment' => '2026-01-01']],
            'advance_applications' => [],
        ] + $extra;
    }

    private function emitir(array $payload): Sale
    {
        app(SaleController::class)->store(new Request($payload));

        return Sale::orderByDesc('id')->first();
    }

    private function html(Sale $venta, string $plantilla): string
    {
        $venta = Sale::with(['client', 'user', 'sale_details.product', 'sale_details.product_categorie', 'sale_payments'])->find($venta->id);

        return view('pdf.' . $plantilla, [
            'venta' => $venta,
            'empresa' => Company::first() ?? new Company(['razon_social' => 'Empresa Test', 'ruc' => '20000000001']),
            'qr' => '', 'logo' => null,
        ])->render();
    }

    // CA1 — modelo: el texto editado sale SOLO, sin el título ni el detalle de Agencia
    public function test_ca1_descripcion_para_sunat_devuelve_solo_el_texto_editado(): void
    {
        $this->emisor();
        $venta = $this->emitir($this->payload($this->empresa(), ['descripcion_linea' => self::TEXTO]));
        $detalle = $venta->sale_details()->first();

        $this->assertSame(self::TEXTO, $detalle->descripcionParaSunat());

        // Aunque la línea traiga detalle de Agencia, el texto editado manda sin concatenar.
        $detalle->descripcion_detalle = 'Detalle de agencia';
        $this->assertSame(self::TEXTO, $detalle->descripcionParaSunat());
    }

    // CA1 — XML (Greenter)
    public function test_ca1_el_xml_lleva_solo_el_texto_editado(): void
    {
        $this->emisor();
        $venta = $this->emitir($this->payload($this->empresa(), ['descripcion_linea' => self::TEXTO]));

        $lineas = app(GreenterService::class)->getDetallesComprobante($venta->sale_details()->with('product')->get());

        $this->assertSame(self::TEXTO, $lineas[0]->getDescripcion());
    }

    // CA1 — PDF A4 y ticket: el texto editado va escapado, con saltos de línea, y SIN el nombre del catálogo
    public function test_ca1_pdf_a4_y_ticket_muestran_solo_el_texto_editado_escapado(): void
    {
        $this->emisor();
        $malicioso = "Equipo <script>alert(1)</script>\nSegunda línea";
        $venta = $this->emitir($this->payload($this->empresa(), ['descripcion_linea' => $malicioso]));

        foreach (['a4', 'ticket80mm'] as $plantilla) {
            $html = $this->html($venta, $plantilla);
            $this->assertStringContainsString('Equipo &lt;script&gt;alert(1)&lt;/script&gt;<br />', $html, $plantilla);
            $this->assertStringContainsString('Segunda línea', $html, $plantilla);
            $this->assertStringNotContainsString('<script>alert(1)', $html, $plantilla);
            $this->assertStringNotContainsString('Antivirus ESET NOD32', $html, $plantilla);
        }
    }

    // CA2 — sin descripción editada todo sigue como hoy (retail: título; Agencia: título + detalle)
    public function test_ca2_sin_descripcion_editada_sale_el_titulo_y_agencia_titulo_mas_detalle(): void
    {
        $this->emisor();
        $cliente = $this->empresa();
        foreach ([[], ['descripcion_linea' => ''], ['descripcion_linea' => "  \n "], ['descripcion_linea' => null]] as $linea) {
            $venta = $this->emitir($this->payload($cliente, $linea));
            $detalle = $venta->sale_details()->first();

            $this->assertNull($detalle->descripcion_linea, 'vacío se guarda como null');
            $this->assertSame('Antivirus ESET NOD32 1 PC 1 año', $detalle->descripcionParaSunat());
            $this->assertStringContainsString('Antivirus ESET NOD32 1 PC 1 año', $this->html($venta, 'a4'));
            $this->assertStringContainsString('Antivirus ESET NOD32 1 PC 1 año', $this->html($venta, 'ticket80mm'));
        }

        $detalle->descripcion_detalle = 'Vuelo Lima-Tarapoto';
        $this->assertSame('Antivirus ESET NOD32 1 PC 1 año - Vuelo Lima-Tarapoto', $detalle->descripcionParaSunat());
    }

    // CA3 — la nota de crédito lleva la misma descripción (BD, XML y PDF de la nota)
    public function test_ca3_la_nota_de_credito_copia_la_descripcion_de_la_venta(): void
    {
        $this->emisor();
        $venta = $this->emitir($this->payload($this->empresa(), ['descripcion_linea' => self::TEXTO], ['tipo_comprobante_codigo' => '01']));
        Sale::where('id', $venta->id)->update(['correlativo' => 1, 'n_operacion' => 'F001-00000001', 'xml' => '<x/>', 'cdr' => 'cdr']);

        $respuesta = app(NotaElectronicaController::class)->store(new Request([
            'sale_id' => $venta->id, 'tipo_doc' => '07', 'tipo_afectacion' => 'total',
            'cod_motivo' => '01', 'des_motivo' => 'Anulación de la operación',
        ]));
        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));

        $nota = Note::with('note_details.product')->find($respuesta->getData(true)['note']['id']);
        $linea = $nota->note_details->first();
        $this->assertSame(self::TEXTO, $linea->descripcion_linea);
        $this->assertSame(self::TEXTO, $linea->descripcionParaSunat());

        $xml = app(GreenterService::class)->getDetallesComprobante($nota->note_details);
        $this->assertSame(self::TEXTO, $xml[0]->getDescripcion());

        $html = view('pdf.nota_a4', [
            'nota' => $nota,
            'empresa' => Company::first() ?? new Company(['razon_social' => 'Empresa Test', 'ruc' => '20000000001']),
            'qr' => '', 'logo' => null,
        ])->render();
        $this->assertStringContainsString('Vence el 18/11/2027', $html);
        $this->assertStringNotContainsString('Antivirus ESET NOD32', $html);
    }

    // CA3 — una nota de una línea SIN texto editado sigue diciendo el título
    public function test_ca3_nota_sin_texto_editado_usa_el_titulo(): void
    {
        $this->emisor();
        $venta = $this->emitir($this->payload($this->empresa(), [], ['tipo_comprobante_codigo' => '01']));
        Sale::where('id', $venta->id)->update(['correlativo' => 1, 'n_operacion' => 'F001-00000001', 'xml' => '<x/>', 'cdr' => 'cdr']);

        $respuesta = app(NotaElectronicaController::class)->store(new Request([
            'sale_id' => $venta->id, 'tipo_doc' => '07', 'tipo_afectacion' => 'total',
            'cod_motivo' => '01', 'des_motivo' => 'Anulación de la operación',
        ]));
        $nota = Note::with('note_details.product')->find($respuesta->getData(true)['note']['id']);

        $this->assertNull($nota->note_details->first()->descripcion_linea);
        $this->assertSame('Antivirus ESET NOD32 1 PC 1 año', $nota->note_details->first()->descripcionParaSunat());
    }

    // CA4 — más de 500 caracteres → 422, en store y en update, sin dejar nada a medias
    public function test_ca4_mas_de_500_caracteres_da_422_en_store_y_update(): void
    {
        $this->emisor();
        $cliente = $this->empresa();
        $largo = str_repeat('a', 501);

        try {
            app(SaleController::class)->store(new Request($this->payload($cliente, ['descripcion_linea' => $largo])));
            $this->fail('Debía rechazarse con 422.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sale_details.0.descripcion_linea', $e->errors());
        }
        $this->assertSame(0, Sale::count());

        $venta = $this->emitir($this->payload($cliente, ['descripcion_linea' => 'corta']));
        $detalle = $venta->sale_details()->first();
        try {
            app(SaleController::class)->update(
                new Request($this->payload($cliente, ['id' => $detalle->id, 'descripcion_linea' => $largo])),
                (string) $venta->id
            );
            $this->fail('Debía rechazarse con 422.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sale_details.0.descripcion_linea', $e->errors());
        }
        $this->assertSame('corta', $detalle->fresh()->descripcion_linea);

        // Exactamente 500 se acepta.
        $ok = $this->emitir($this->payload($cliente, ['descripcion_linea' => str_repeat('b', 500)]));
        $this->assertSame(500, mb_strlen($ok->sale_details()->first()->descripcion_linea));
    }

    // CA5 — el catálogo no cambia
    public function test_ca5_el_producto_del_catalogo_no_cambia(): void
    {
        $this->emisor();
        $venta = $this->emitir($this->payload($this->empresa(), ['descripcion_linea' => self::TEXTO]));

        $this->assertSame('Antivirus ESET NOD32 1 PC 1 año', $this->producto->fresh()->title);
        $this->assertSame('Antivirus ESET NOD32 1 PC 1 año', $venta->sale_details()->first()->product->title);
    }

    // edit.vue (pantalla vieja, hasta T-110): editar una venta con descripción editada no la borra
    public function test_update_conserva_la_descripcion_si_la_linea_no_trae_el_campo_y_la_cambia_si_lo_trae(): void
    {
        $this->emisor();
        $cliente = $this->empresa();
        $venta = $this->emitir($this->payload($cliente, ['descripcion_linea' => self::TEXTO]));
        $id = $venta->sale_details()->first()->id;

        // 1) la pantalla vieja no manda el campo (cambia la cantidad, por ejemplo)
        app(SaleController::class)->update(new Request($this->payload($cliente, ['id' => $id, 'quantity' => 3, 'subtotal' => 150, 'mto_valor_venta' => 150, 'mto_base_igv' => 150, 'igv' => 27, 'total_impuestos' => 27])), (string) $venta->id);
        $this->assertSame(self::TEXTO, SaleDetail::find($id)->descripcion_linea);
        $this->assertSame(3.0, (float) SaleDetail::find($id)->quantity);

        // 2) la manda tal cual la recibió de SaleResource: se conserva
        app(SaleController::class)->update(new Request($this->payload($cliente, ['id' => $id, 'descripcion_linea' => self::TEXTO])), (string) $venta->id);
        $this->assertSame(self::TEXTO, SaleDetail::find($id)->descripcion_linea);

        // 3) la cambia
        app(SaleController::class)->update(new Request($this->payload($cliente, ['id' => $id, 'descripcion_linea' => 'Otro texto'])), (string) $venta->id);
        $this->assertSame('Otro texto', SaleDetail::find($id)->descripcion_linea);

        // 4) «Volver al nombre del catálogo» (vacío) la quita
        app(SaleController::class)->update(new Request($this->payload($cliente, ['id' => $id, 'descripcion_linea' => ''])), (string) $venta->id);
        $this->assertNull(SaleDetail::find($id)->descripcion_linea);
    }

    // SaleResource expone el campo para que edit.vue (y la pantalla nueva) lo reciba y lo reenvíe
    public function test_sale_resource_expone_descripcion_linea(): void
    {
        $this->emisor();
        $venta = $this->emitir($this->payload($this->empresa(), ['descripcion_linea' => self::TEXTO]));
        $venta = Sale::with(['client', 'user', 'sale_details.product', 'sale_details.product_categorie', 'sale_payments'])->find($venta->id);

        $datos = SaleResource::make($venta)->resolve();

        $this->assertSame(self::TEXTO, $datos['sale_details'][0]['descripcion_linea']);
    }
}
