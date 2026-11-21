<?php

namespace Tests\Feature;

use App\Http\Controllers\Sale\SaleController;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\Sale\SerieComprobante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Creditos\CreditosTestCase;

// T-116: SaleController::update() guarda el anticipo de un comprobante previo
// (n_comprobante_anticipo / amount_anticipo). Postgres real, transacción por test.
class AnticipoEdicionVentaTest extends CreditosTestCase
{
    private Client $cliente;
    private Product $producto;
    private Sale $venta;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create(['name' => 'Sede T-116 ' . uniqid(), 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $branch->id, 'tipo_comprobante_codigo' => 'NV', 'moneda' => 'PEN', 'serie' => 'NV01',
            'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => '2026-01-01', 'activo' => true,
        ]);
        $usuario = $this->usuario(['emitir_nota_venta']);
        $usuario->update(['branch_id' => $branch->id]);
        $usuario = $usuario->fresh();
        $this->abrirCaja($usuario);
        Auth::guard('api')->setUser($usuario);

        $this->cliente = Client::factory()->empresa()->create(['n_document' => '20111111111']);
        $this->producto = Product::create([
            'title' => 'Producto T-116', 'sku' => 'SKU-T116-' . uniqid(),
            'categorie_id' => Categorie::create(['title' => 'Cat T116', 'state' => 1])->id,
            'price_general' => 50, 'price_company' => 50, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 50,
            'is_discount' => false, 'disponiblidad' => 1, 'include_igv' => false, 'is_icbper' => false,
            'is_ivap' => false, 'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
        ]);

        app(SaleController::class)->store(new Request($this->payload()));
        $this->venta = Sale::orderByDesc('id')->first();
    }

    private function payload(array $extra = []): array
    {
        $linea = isset($this->venta) ? ['id' => $this->venta->sale_details()->first()->id] : [];

        return array_replace([
            'date' => '2026-01-01', 'tipo_comprobante_codigo' => 'NV', 'n_transaction' => '00000001',
            'client_id' => $this->cliente->id, 'type_client' => 1, 'cod_tipo_doc_cliente' => '6',
            'currency' => 'PEN', 'is_exportacion' => 0, 'destino' => 'nacional', 'type_payment' => 1,
            'subtotal' => 100.00, 'igv' => 18.00, 'total' => 118.00, 'discount' => 0, 'discount_global' => 0,
            'mto_oper_gravadas' => 100, 'total_impuestos' => 18, 'valor_venta' => 100, 'mto_imp_venta' => 118, 'redondeo' => 0,
            'retencion_igv' => 0, 'state_payment' => 3, 'debt' => 0, 'paid_out' => 50,
            'sale_details' => [$linea + [
                'product' => ['id' => $this->producto->id, 'categorie_id' => $this->producto->categorie_id],
                'unidad_medida' => 'NIU', 'quantity' => 2, 'price_base' => 50, 'price_final' => 59, 'discount' => 0,
                'subtotal' => 100, 'mto_valor_venta' => 100, 'mto_base_igv' => 100, 'porcentaje_igv' => 18,
                'igv' => 18, 'total_impuestos' => 18, 'tip_afe_igv' => '10',
            ]],
            'payments' => [['method_payment' => 'EFECTIVO', 'amount' => 50.00, 'date_payment' => '2026-01-01']],
            'advance_applications' => [],
        ], $extra);
    }

    private function editar(array $extra): Sale
    {
        app(SaleController::class)->update(new Request($this->payload($extra)), (string) $this->venta->id);

        return $this->venta->fresh();
    }

    public function test_update_guarda_el_anticipo(): void
    {
        $venta = $this->editar(['n_comprobante_anticipo' => ' F001-00000123 ', 'amount_anticipo' => 68]);

        $this->assertSame('F001-00000123', $venta->n_comprobante_anticipo);
        $this->assertSame(68.0, (float) $venta->amount_anticipo);
        $this->assertSame(50.0, $venta->resumenImpresion()['total_sales']); // 118 − 68
    }

    public function test_sin_comprobante_el_anticipo_queda_en_cero(): void
    {
        $this->editar(['n_comprobante_anticipo' => 'F001-00000123', 'amount_anticipo' => 68]);

        // se quitó la condición «Con adelanto»: la pantalla manda null y el monto que tenía
        $venta = $this->editar(['n_comprobante_anticipo' => null, 'amount_anticipo' => 68]);

        $this->assertNull($venta->n_comprobante_anticipo);
        $this->assertSame(0.0, (float) $venta->amount_anticipo);
    }

    public function test_un_payload_sin_los_campos_conserva_el_anticipo(): void
    {
        $this->editar(['n_comprobante_anticipo' => 'F001-00000123', 'amount_anticipo' => 68]);

        $venta = $this->editar([]);

        $this->assertSame('F001-00000123', $venta->n_comprobante_anticipo);
        $this->assertSame(68.0, (float) $venta->amount_anticipo);
    }

    public function test_anticipo_mayor_que_el_total_da_422_y_no_cambia_la_venta(): void
    {
        try {
            $this->editar(['n_comprobante_anticipo' => 'F001-00000123', 'amount_anticipo' => 118.01]);
            $this->fail('Debió rechazar el anticipo mayor que el total.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('mayor que el total', $e->getMessage());
        }

        $this->assertSame(0.0, (float) $this->venta->fresh()->amount_anticipo);
    }

    public function test_anticipo_negativo_o_no_numerico_da_422(): void
    {
        foreach ([-1, 'abc'] as $monto) {
            try {
                $this->editar(['n_comprobante_anticipo' => 'F001-00000123', 'amount_anticipo' => $monto]);
                $this->fail("Debió rechazar el monto {$monto}.");
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }
}
