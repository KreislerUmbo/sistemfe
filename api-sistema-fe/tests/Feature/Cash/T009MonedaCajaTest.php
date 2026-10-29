<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Services\CashCorrectionService;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * T-009 (Caja, paso A): cada movimiento de caja guarda la moneda real del dinero, sin cambiar nada
 * de lo que se ve. Tests escritos por Opus antes de implementar (parte 1); los de venta, adelanto,
 * cobro de crédito y el comando los agrega quien implementa (ver la tarea).
 */
final class T009MonedaCajaTest extends CreditosTestCase
{
    private function efectivo(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['code' => 'EFECTIVO'],
            ['name' => 'Efectivo', 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => true]
        );
    }

    private function movimiento(CashSession $sesion, string $moneda, float $monto = 100): CashMovement
    {
        return CashMovement::create([
            'cash_session_id' => $sesion->id,
            'type' => 'advance_received',
            'payment_method_id' => $this->efectivo()->id,
            'direction' => 'in',
            'amount' => $monto,
            'currency' => $moneda,
            'reference_type' => 'advance',
            'reference_id' => 999999,
            'status' => 'confirmed',
            'created_by' => $sesion->opened_by,
        ]);
    }

    public function test_el_movimiento_guarda_la_moneda(): void
    {
        $sesion = $this->abrirCaja($this->usuario(['cash.open_session']));

        $this->assertSame('USD', $this->movimiento($sesion, 'USD')->fresh()->currency);
        $this->assertSame('PEN', $this->movimiento($sesion, 'PEN')->fresh()->currency);
    }

    // CA3: la corrección devuelve el mismo dinero, en la misma moneda.
    public function test_ca3_la_correccion_copia_la_moneda_del_original(): void
    {
        $cajero = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($cajero);
        $original = $this->movimiento($sesion, 'USD', 250);

        $correccion = app(CashCorrectionService::class)->revertirMovimiento($original, $cajero);

        $this->assertSame('USD', $correccion->fresh()->currency);
        $this->assertSame('out', $correccion->direction);
    }

    // Las filas que ya existían antes de la migración quedan en soles (default de la columna);
    // el comando caja:completar-moneda pasa a USD las que correspondan.
    public function test_una_fila_insertada_sin_moneda_queda_en_soles(): void
    {
        $sesion = $this->abrirCaja($this->usuario(['cash.open_session']));
        $id = \DB::table('cash_movements')->insertGetId([
            'cash_session_id' => $sesion->id, 'type' => 'opening_fund', 'payment_method_id' => $this->efectivo()->id,
            'direction' => 'in', 'amount' => 50, 'status' => 'confirmed', 'created_by' => $sesion->opened_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('PEN', CashMovement::find($id)->currency);
    }

    // ── Parte 1: los lugares que crean movimientos ─────────────────────────────

    private function usuarioConSede(array $permisos): \App\Models\User
    {
        $branch = \App\Models\Cash\Branch::create(['name' => 'Sede T009 ' . uniqid(), 'is_active' => true]);
        foreach ([['NV', 'PEN', 'NV01'], ['NV', 'USD', 'NV02'], ['03', 'PEN', 'B001'], ['03', 'USD', 'B002']] as [$tipo, $moneda, $serie]) {
            \App\Models\Sale\SerieComprobante::create([
                'branch_id' => $branch->id, 'tipo_comprobante_codigo' => $tipo, 'moneda' => $moneda, 'serie' => $serie,
                'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => '2026-01-01', 'activo' => true,
            ]);
        }
        $usuario = $this->usuario($permisos);
        $usuario->update(['branch_id' => $branch->id]);
        $usuario = $usuario->fresh();
        \Illuminate\Support\Facades\Auth::guard('api')->setUser($usuario);

        return $usuario;
    }

    private function payloadVentaNvConPago(\App\Models\Client\Client $cliente, \App\Models\Product\Product $producto, string $moneda): array
    {
        return [
            'date' => '2026-01-01', 'tipo_comprobante_codigo' => 'NV', 'n_transaction' => '00000001',
            'client_id' => $cliente->id, 'type_client' => 1, 'cod_tipo_doc_cliente' => '1',
            'currency' => $moneda, 'is_exportacion' => 0, 'destino' => 'nacional', 'type_payment' => 1,
            'subtotal' => 100.00, 'igv' => 18.00, 'total' => 118.00, 'discount' => 0, 'discount_global' => 0,
            'retencion_igv' => 0, 'state_payment' => 3, 'debt' => 0, 'paid_out' => 118,
            'sale_details' => [[
                'product' => ['id' => $producto->id, 'categorie_id' => $producto->categorie_id],
                'unidad_medida' => 'NIU', 'quantity' => 2, 'price_base' => 50, 'price_final' => 59, 'discount' => 0,
                'subtotal' => 100, 'mto_valor_venta' => 100, 'mto_base_igv' => 100, 'porcentaje_igv' => 18,
                'igv' => 18, 'tip_afe_igv' => '10',
            ]],
            'payments' => [['method_payment' => 'EFECTIVO', 'amount' => 118.00, 'date_payment' => '2026-01-01']],
            'advance_applications' => [],
        ];
    }

    private function productoVenta(): \App\Models\Product\Product
    {
        $categoria = \App\Models\Product\Categorie::create(['title' => 'Cat T009', 'state' => 1]);

        return \App\Models\Product\Product::create([
            'title' => 'Producto T009', 'sku' => 'SKU-T009-' . uniqid(), 'categorie_id' => $categoria->id,
            'price_general' => 50, 'price_company' => 50, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 50,
            'is_discount' => false, 'disponiblidad' => 1, 'include_igv' => false, 'is_icbper' => false,
            'is_ivap' => false, 'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
        ]);
    }

    // CA1
    public function test_ca1_el_pago_de_una_venta_guarda_la_moneda_de_la_venta(): void
    {
        $usuario = $this->usuarioConSede(['emitir_nota_venta']);
        $sesion = $this->abrirCaja($usuario);
        $producto = $this->productoVenta();

        foreach (['USD', 'PEN'] as $moneda) {
            $cliente = $this->cliente();
            app(\App\Http\Controllers\Sale\SaleController::class)->store(
                new \Illuminate\Http\Request($this->payloadVentaNvConPago($cliente, $producto, $moneda))
            );
            $movimiento = CashMovement::where('cash_session_id', $sesion->id)->where('type', 'sale_payment')
                ->orderByDesc('id')->first();
            $this->assertSame($moneda, $movimiento->currency);
        }
    }

    // CA2
    public function test_ca2_un_adelanto_en_dolares_guarda_el_movimiento_en_dolares(): void
    {
        $usuario = $this->usuarioConSede(['advances.create', 'emitir_nota_venta']);
        $sesion = $this->abrirCaja($usuario);
        $cliente = $this->cliente();

        app(\App\Http\Controllers\Advance\AdvanceController::class)->store(new \Illuminate\Http\Request([
            'client_id' => $cliente->id, 'amount' => 1000, 'currency' => 'USD',
            'payment_method' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]));

        $movimiento = CashMovement::where('cash_session_id', $sesion->id)->where('type', 'advance_received')->firstOrFail();
        $this->assertSame('USD', $movimiento->currency);
    }

    private function ventaACredito(\App\Models\Client\Client $cliente, string $moneda): \App\Models\Sale\Sale
    {
        return \App\Models\Sale\Sale::factory()->create([
            'client_id' => $cliente->id, 'currency' => $moneda, 'total' => 100, 'saldo_pendiente' => 100,
        ]);
    }

    private function cobrar(\App\Models\Client\Client $cliente, array $ventas): void
    {
        app(\App\Http\Controllers\Credit\CreditPaymentController::class)->store(new \Illuminate\Http\Request([
            'monto_total' => 50 * count($ventas), 'fecha_pago' => '2026-01-01', 'medio_pago' => 'EFECTIVO',
            'aplicaciones' => array_map(fn ($v) => ['sale_id' => $v->id, 'installment_id' => null, 'monto_aplicado' => 50], $ventas),
        ]), $cliente);
    }

    public function test_el_cobro_de_credito_guarda_la_moneda_de_la_venta(): void
    {
        $usuario = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($usuario);
        $cliente = $this->cliente();

        $this->cobrar($cliente, [$this->ventaACredito($cliente, 'USD')]);

        $movimiento = CashMovement::where('cash_session_id', $sesion->id)->where('type', 'installment_payment')->firstOrFail();
        $this->assertSame('USD', $movimiento->currency);
    }

    // CA6
    public function test_ca6_un_recibo_con_ventas_en_soles_y_dolares_se_rechaza_sin_escribir(): void
    {
        $usuario = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($usuario);
        $cliente = $this->cliente();
        $ventas = [$this->ventaACredito($cliente, 'PEN'), $this->ventaACredito($cliente, 'USD')];
        $recibosAntes = \App\Models\Credit\PaymentReceipt::count();

        try {
            $this->cobrar($cliente, $ventas);
            $this->fail('Se esperaba un 422.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('mezclar', $e->getMessage());
        }

        $this->assertSame($recibosAntes, \App\Models\Credit\PaymentReceipt::count());
        $this->assertSame(0, CashMovement::where('cash_session_id', $sesion->id)->where('type', 'installment_payment')->count());
    }

    public function test_la_apertura_de_caja_guarda_el_fondo_en_soles(): void
    {
        $usuario = $this->usuarioConSede(['cash.open_session']);
        $caja = \App\Models\Cash\CashRegister::create(['branch_id' => $usuario->branch_id, 'name' => 'Caja ' . uniqid(), 'is_active' => true]);

        app(\App\Http\Controllers\Cash\CashSessionController::class)->open(new \Illuminate\Http\Request([
            'cash_register_id' => $caja->id, 'opening_amount' => 100,
        ]));

        $movimiento = CashMovement::where('type', 'opening_fund')->orderByDesc('id')->firstOrFail();
        $this->assertSame('PEN', $movimiento->currency);
    }

    public function test_normalizar_moneda(): void
    {
        $this->assertSame('PEN', CashMovement::normalizarMoneda('S/.'));
        $this->assertSame('USD', CashMovement::normalizarMoneda('US$'));
        $this->assertNull(CashMovement::normalizarMoneda('EUR'));
    }
}
