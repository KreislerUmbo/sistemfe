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
use Tests\Feature\Creditos\CreditosTestCase;

// T-116: GET sales/{id} devuelve el cronograma vigente de una venta a crédito, para que la
// edición abra con las cuotas guardadas. Postgres real, transacción por test.
class CronogramaEdicionVentaTest extends CreditosTestCase
{
    private Client $cliente;
    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create(['name' => 'Sede T-116c ' . uniqid(), 'is_active' => true]);
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
            'title' => 'Producto T-116c', 'sku' => 'SKU-T116C-' . uniqid(),
            'categorie_id' => Categorie::create(['title' => 'Cat T116c', 'state' => 1])->id,
            'price_general' => 50, 'price_company' => 50, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 50,
            'is_discount' => false, 'disponiblidad' => 1, 'include_igv' => false, 'is_icbper' => false,
            'is_ivap' => false, 'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
        ]);
    }

    private function payload(array $cronograma, ?Sale $venta = null): array
    {
        $linea = $venta ? ['id' => $venta->sale_details()->first()->id] : [];

        return [
            'date' => '2026-01-01', 'tipo_comprobante_codigo' => 'NV', 'n_transaction' => '00000001',
            'client_id' => $this->cliente->id, 'type_client' => 1, 'cod_tipo_doc_cliente' => '6',
            'currency' => 'PEN', 'is_exportacion' => 0, 'destino' => 'nacional', 'type_payment' => 2,
            'subtotal' => 100.00, 'igv' => 18.00, 'total' => 118.00, 'discount' => 0, 'discount_global' => 0,
            'mto_oper_gravadas' => 100, 'total_impuestos' => 18, 'valor_venta' => 100, 'mto_imp_venta' => 118, 'redondeo' => 0,
            'retencion_igv' => 0, 'state_payment' => 1, 'debt' => 118, 'paid_out' => 0,
            'credit_type' => 'cuotas_fijas', 'cronograma' => $cronograma, 'aplica_mora' => false,
            'sale_details' => [$linea + [
                'product' => ['id' => $this->producto->id, 'categorie_id' => $this->producto->categorie_id],
                'unidad_medida' => 'NIU', 'quantity' => 2, 'price_base' => 50, 'price_final' => 59, 'discount' => 0,
                'subtotal' => 100, 'mto_valor_venta' => 100, 'mto_base_igv' => 100, 'porcentaje_igv' => 18,
                'igv' => 18, 'total_impuestos' => 18, 'tip_afe_igv' => '10',
            ]],
            'payments' => [],
            'advance_applications' => [],
        ];
    }

    public function test_show_devuelve_las_cuotas_vigentes_y_no_las_anuladas(): void
    {
        app(SaleController::class)->store(new Request($this->payload([
            ['numero_cuota' => 1, 'monto_programado' => 59, 'fecha_vencimiento' => '2026-02-01'],
            ['numero_cuota' => 2, 'monto_programado' => 59, 'fecha_vencimiento' => '2026-03-01'],
        ])));
        $venta = Sale::orderByDesc('id')->first();

        // la edición reemplaza el cronograma por una sola cuota: las dos de antes quedan anuladas
        app(SaleController::class)->update(new Request($this->payload([
            ['numero_cuota' => 1, 'monto_programado' => 118, 'fecha_vencimiento' => '2026-12-31'],
        ], $venta)), (string) $venta->id);

        $respuesta = app(SaleController::class)->show((string) $venta->id)->getData(true);

        $this->assertSame(
            [['numero_cuota' => 1, 'monto_programado' => 118, 'fecha_vencimiento' => '2026-12-31']],
            $respuesta['cronograma']
        );
    }

    // T-116 (decisión del usuario, 21-nov): sin cobros, una venta a crédito pasa a contado y sus cuotas
    // quedan anuladas (antes daba 422 siempre, porque toda venta a crédito guardada tiene cuotas).
    private function payloadContado(Sale $venta): array
    {
        $datos = array_replace($this->payload([], $venta), [
            'type_payment' => 1, 'state_payment' => 3, 'debt' => 0, 'paid_out' => 118,
            'payments' => [['method_payment' => 'EFECTIVO', 'amount' => 118.00, 'date_payment' => '2026-01-01']],
        ]);
        unset($datos['credit_type'], $datos['cronograma']);

        return $datos;
    }

    private function ventaAlContadoPagada(): Sale
    {
        $datos = array_replace($this->payload([]), [
            'type_payment' => 1, 'state_payment' => 3, 'debt' => 0, 'paid_out' => 118,
            'payments' => [['method_payment' => 'EFECTIVO', 'amount' => 118.00, 'date_payment' => '2026-01-01']],
        ]);
        unset($datos['credit_type'], $datos['cronograma']);
        app(SaleController::class)->store(new Request($datos));

        return Sale::orderByDesc('id')->first();
    }

    private function ventaACreditoConUnaCuota(): Sale
    {
        app(SaleController::class)->store(new Request($this->payload([
            ['numero_cuota' => 1, 'monto_programado' => 118, 'fecha_vencimiento' => '2026-12-31'],
        ])));

        return Sale::orderByDesc('id')->first();
    }

    public function test_una_venta_a_credito_sin_cobros_pasa_a_contado_y_anula_sus_cuotas(): void
    {
        $venta = $this->ventaACreditoConUnaCuota();
        $venta->update(['aplica_mora' => true, 'tasa_mora' => 1, 'tipo_mora' => 'porcentaje_diario']);

        app(SaleController::class)->update(new Request($this->payloadContado($venta)), (string) $venta->id);

        $venta->refresh();
        $this->assertSame(1, (int) $venta->type_payment);
        $this->assertSame('contado', $venta->condicion_pago);
        $this->assertEquals(0, (float) $venta->saldo_pendiente);
        $this->assertFalse((bool) $venta->aplica_mora);
        $this->assertSame(0, $venta->cuotasVigentes()->count());
        $this->assertSame(['anulada'], $venta->installments()->pluck('estado')->unique()->values()->all());
        $this->assertSame([], app(SaleController::class)->show((string) $venta->id)->getData(true)['cronograma']);
    }

    public function test_una_venta_a_credito_con_una_cuota_cobrada_no_pasa_a_contado(): void
    {
        $venta = $this->ventaACreditoConUnaCuota();
        app(\App\Http\Controllers\Credit\CreditPaymentController::class)->store(new Request([
            'monto_total' => 50, 'fecha_pago' => '2026-01-01', 'medio_pago' => 'EFECTIVO',
            'aplicaciones' => [['sale_id' => $venta->id, 'installment_id' => $venta->cuotasVigentes()->first()->id, 'monto_aplicado' => 50]],
        ]), $this->cliente);

        try {
            app(SaleController::class)->update(new Request($this->payloadContado($venta)), (string) $venta->id);
            $this->fail('Debió rechazar el paso a contado.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('cuotas cobradas', $e->getMessage());
        }

        $this->assertSame(2, (int) $venta->fresh()->type_payment);
        $this->assertSame(1, $venta->cuotasVigentes()->count());
    }

    // T-119: borrar el único pago de una venta al contado la deja «pendiente» (1), no «parcial» (2).
    public function test_borrar_el_unico_pago_de_una_venta_al_contado_la_deja_pendiente(): void
    {
        $venta = $this->ventaAlContadoPagada();

        app(\App\Http\Controllers\Sale\SalePaymentController::class)
            ->destroy((string) \App\Models\Sale\SalePayment::where('sale_id', $venta->id)->value('id'));

        $venta->refresh();
        $this->assertSame(1, (int) $venta->state_payment);
        $this->assertEquals(0, (float) $venta->paid_out);
        $this->assertEquals(118, (float) $venta->debt);
    }

    // T-119: la edición ya no borra pagos con DELETE sale_payments (no mueve la caja); los quita del
    // payload del PATCH, que corrige el ingreso en caja (caso de la venta 41 de umbo).
    public function test_quitar_el_pago_al_editar_una_venta_al_contado_corrige_la_caja(): void
    {
        $venta = $this->ventaAlContadoPagada();
        $ingreso = \App\Models\Cash\CashMovement::where('reference_type', 'sale')->where('reference_id', $venta->id)
            ->where('type', 'sale_payment')->firstOrFail();

        $sinPago = array_replace($this->payloadContado($venta), [
            'state_payment' => 1, 'debt' => 118, 'paid_out' => 0, 'payments' => [],
        ]);
        app(SaleController::class)->update(new Request($sinPago), (string) $venta->id);

        $this->assertNotNull($ingreso->fresh()->corrected_by);
        $this->assertTrue(\App\Models\Cash\CashMovement::where('corrected_movement_id', $ingreso->id)->where('type', 'correction')->exists());
        $this->assertSame(0, \App\Models\Sale\SalePayment::where('sale_id', $venta->id)->count());
        $this->assertEquals(118, (float) $venta->fresh()->saldo_pendiente);
    }

    public function test_show_de_una_venta_al_contado_no_trae_cronograma(): void
    {
        $datos = array_replace($this->payload([]), [
            'type_payment' => 1, 'state_payment' => 3, 'debt' => 0, 'paid_out' => 118,
            'payments' => [['method_payment' => 'EFECTIVO', 'amount' => 118.00, 'date_payment' => '2026-01-01']],
        ]);
        unset($datos['credit_type'], $datos['cronograma']);
        app(SaleController::class)->store(new Request($datos));

        $respuesta = app(SaleController::class)->show((string) Sale::orderByDesc('id')->value('id'))->getData(true);

        $this->assertSame([], $respuesta['cronograma']);
    }
}
