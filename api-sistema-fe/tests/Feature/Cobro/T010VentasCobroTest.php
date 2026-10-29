<?php

declare(strict_types=1);

namespace Tests\Feature\Cobro;

use App\Http\Controllers\Cash\PaymentMethodController;
use App\Http\Controllers\Sale\SaleController;
use App\Models\Cash\Branch;
use App\Models\Cash\CashMovement;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\Sale\SalePayment;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use App\Services\Cobro\CobroVentaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * T-010 (parte 1): Ventas cobra con CobroVentaService — n.º de operación, monedas por forma
 * de pago y vuelto revertido al eliminar. El contrato del servicio lo prueban
 * CobroVentaServiceTest (Opus); aquí, la integración con SaleController y las formas de pago.
 */
final class T010VentasCobroTest extends CreditosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Formas de pago como las deja la migración (Yape solo en soles).
        PaymentMethod::firstOrCreate(
            ['code' => 'YAPE'],
            ['name' => 'Yape', 'is_active' => true, 'sort_order' => 2, 'affects_cash_count' => false]
        )->update(['monedas' => ['PEN'], 'is_active' => true]);
        $this->efectivo->update(['monedas' => ['PEN', 'USD'], 'affects_cash_count' => true]);
    }

    private function usuarioConSede(array $permisos): User
    {
        $branch = Branch::create(['name' => 'Sede T010 ' . uniqid(), 'is_active' => true]);
        foreach ([['NV', 'PEN', 'NV01'], ['NV', 'USD', 'NV02']] as [$tipo, $moneda, $serie]) {
            SerieComprobante::create([
                'branch_id' => $branch->id, 'tipo_comprobante_codigo' => $tipo, 'moneda' => $moneda, 'serie' => $serie,
                'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => '2026-01-01', 'activo' => true,
            ]);
        }
        $usuario = $this->usuario($permisos);
        $usuario->update(['branch_id' => $branch->id]);
        $usuario = $usuario->fresh();
        Auth::guard('api')->setUser($usuario);

        return $usuario;
    }

    private function producto(): Product
    {
        $categoria = Categorie::create(['title' => 'Cat T010', 'state' => 1]);

        return Product::create([
            'title' => 'Producto T010', 'sku' => 'SKU-T010-' . uniqid(), 'categorie_id' => $categoria->id,
            'price_general' => 50, 'price_company' => 50, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 50,
            'is_discount' => false, 'disponiblidad' => 1, 'include_igv' => false, 'is_icbper' => false,
            'is_ivap' => false, 'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
        ]);
    }

    private function payload(Client $cliente, Product $producto, array $pagos, string $moneda = 'PEN'): array
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
                'igv' => 18, 'total_impuestos' => 18, 'tip_afe_igv' => '10',
            ]],
            'payments' => $pagos,
            'advance_applications' => [],
        ];
    }

    private function movimientosDe(Sale $venta)
    {
        return CashMovement::where('reference_type', 'sale')->where('reference_id', $venta->id)
            ->whereNull('corrected_by')->where('type', 'sale_payment')->get();
    }

    // CA1
    public function test_ca1_el_nro_de_operacion_se_guarda_y_editar_sin_tocar_pagos_lo_conserva_sin_mover_la_caja(): void
    {
        $usuario = $this->usuarioConSede(['emitir_nota_venta']);
        $this->abrirCaja($usuario);
        $producto = $this->producto();
        $pagos = [['method_payment' => 'YAPE', 'amount' => 118.00, 'date_payment' => '2026-01-01', 'nro_operacion' => '123456']];
        $payload = $this->payload($this->cliente(), $producto, $pagos);

        app(SaleController::class)->store(new Request($payload));
        $venta = Sale::orderByDesc('id')->first();

        $pago = SalePayment::where('sale_id', $venta->id)->sole();
        $this->assertSame('123456', $pago->nro_operacion);
        $this->assertSame('PEN', $pago->moneda_pago);
        $this->assertEquals(118.0, $pago->monto_pagado);
        $this->assertEquals(1.0, $pago->tipo_cambio);
        $this->assertCount(1, $this->movimientosDe($venta));

        app(SaleController::class)->update(new Request($payload), (string) $venta->id);

        $pago = SalePayment::where('sale_id', $venta->id)->sole();
        $this->assertSame('123456', $pago->nro_operacion);
        $this->assertCount(1, $this->movimientosDe($venta), 'firma igual: no se crean movimientos nuevos');
        $this->assertSame(0, CashMovement::where('reference_id', $venta->id)->where('type', 'correction')->count());
    }

    public function test_un_pago_de_mas_en_una_venta_de_ventas_sigue_rechazandose(): void
    {
        $usuario = $this->usuarioConSede(['emitir_nota_venta']);
        $this->abrirCaja($usuario);
        $payload = $this->payload($this->cliente(), $this->producto(), [
            ['method_payment' => 'YAPE', 'amount' => 200.00, 'date_payment' => '2026-01-01'],
        ]);

        try {
            app(SaleController::class)->store(new Request($payload));
            $this->fail('Se esperaba un 422.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('supera el total a pagar', $e->getMessage());
        }
    }

    // CA8
    public function test_ca8_yape_y_plin_nacen_solo_en_soles_y_las_monedas_son_editables(): void
    {
        $this->usuarioConSede(['emitir_nota_venta']);
        $yape = PaymentMethod::where('code', 'YAPE')->first();
        $this->assertSame(['PEN'], $yape->monedas);

        $respuesta = app(PaymentMethodController::class)->update(
            new Request(['code' => 'YAPE', 'name' => 'Yape', 'monedas' => ['PEN', 'USD']]),
            (string) $yape->id
        );
        $this->assertSame(200, $respuesta->getData()->code);
        $this->assertSame(['PEN', 'USD'], $yape->fresh()->monedas);

        $this->expectException(ValidationException::class);
        app(PaymentMethodController::class)->update(
            new Request(['code' => 'YAPE', 'name' => 'Yape', 'monedas' => []]),
            (string) $yape->id
        );
    }

    public function test_eliminar_una_venta_con_vuelto_corrige_tambien_la_salida_de_vuelto(): void
    {
        $usuario = $this->usuarioConSede(['emitir_nota_venta']);
        $sesion = $this->abrirCaja($usuario);
        $venta = Sale::factory()->create(['currency' => 'PEN', 'total' => 100]);
        $cobro = app(CobroVentaService::class);
        $pagos = $cobro->prepararPagos([['method_payment' => 'EFECTIVO', 'amount' => 120]], 'PEN');
        $cobro->registrar($venta, $pagos, $sesion, $usuario, $cobro->validarContraSaldo($pagos, 100, 'PEN', exacto: true));

        app(SaleController::class)->destroy((string) $venta->id);

        $vivos = CashMovement::where('reference_type', 'sale')->where('reference_id', $venta->id)
            ->whereIn('type', ['sale_payment', 'sale_change'])->whereNull('corrected_by')->count();
        $this->assertSame(0, $vivos, 'el cobro y el vuelto quedan corregidos');
        $this->assertSame(2, CashMovement::where('type', 'correction')->where('cash_session_id', $sesion->id)->count());
    }
}
