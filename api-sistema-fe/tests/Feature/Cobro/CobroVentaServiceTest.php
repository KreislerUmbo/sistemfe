<?php

declare(strict_types=1);

namespace Tests\Feature\Cobro;

use App\Models\Cash\CashMovement;
use App\Models\Cash\PaymentMethod;
use App\Models\Sale\Sale;
use App\Models\Sale\SalePayment;
use App\Services\Cobro\CobroVentaService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * T-010 — servicio compartido de cobro (Ventas hoy, Agencia en T-011). Tests escritos por Opus
 * antes de implementar (parte 1); el contrato del servicio está en la tarea.
 *
 * Tipo de cambio = soles por dólar. Venta en soles pagada en dólares: equivalente = monto × tc;
 * venta en dólares pagada en soles: equivalente = monto ÷ tc. Redondeo a 2 decimales.
 */
final class CobroVentaServiceTest extends CreditosTestCase
{
    private function servicio(): CobroVentaService
    {
        return app(CobroVentaService::class);
    }

    private function metodo(string $code, array $monedas, bool $efectivo = false): PaymentMethod
    {
        $metodo = PaymentMethod::firstOrCreate(
            ['code' => $code],
            ['name' => ucfirst(strtolower($code)), 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => $efectivo]
        );
        $metodo->update(['monedas' => $monedas, 'is_active' => true]);

        return $metodo;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->metodo('EFECTIVO', ['PEN', 'USD'], true);
        $this->metodo('YAPE', ['PEN']);
    }

    /** @return HttpException */
    private function falla(callable $accion): HttpException
    {
        try {
            $accion();
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('Se esperaba una HttpException.');
    }

    // ── prepararPagos ──────────────────────────────────────────────────────────

    public function test_un_pago_solo_con_amount_queda_en_la_moneda_de_la_venta(): void
    {
        // Así llega hoy desde Ventas (retail): sin moneda_pago ni monto_pagado.
        [$pago] = $this->servicio()->prepararPagos([
            ['method_payment' => 'EFECTIVO', 'amount' => 118, 'date_payment' => '2026-10-28'],
        ], 'PEN');

        $this->assertSame('PEN', $pago['moneda_pago']);
        $this->assertEquals(118.0, $pago['monto_pagado']);
        $this->assertEquals(1.0, $pago['tipo_cambio']);
        $this->assertEquals(118.0, $pago['amount']);
        $this->assertNull($pago['nro_operacion']);
    }

    public function test_ca3_venta_en_soles_pagada_en_dolares_equivale_a_monto_por_tipo_de_cambio(): void
    {
        [$pago] = $this->servicio()->prepararPagos([
            ['method_payment' => 'EFECTIVO', 'moneda_pago' => 'USD', 'monto_pagado' => 1000, 'tipo_cambio' => 3.75],
        ], 'PEN');

        $this->assertEquals(3750.0, $pago['amount']);
        $this->assertEquals(1000.0, $pago['monto_pagado']);
        $this->assertSame('USD', $pago['moneda_pago']);
    }

    public function test_venta_en_dolares_pagada_en_soles_equivale_a_monto_entre_tipo_de_cambio(): void
    {
        [$pago] = $this->servicio()->prepararPagos([
            ['method_payment' => 'EFECTIVO', 'moneda_pago' => 'PEN', 'monto_pagado' => 100, 'tipo_cambio' => 3.75],
        ], 'USD');

        $this->assertEquals(26.67, $pago['amount']);
    }

    public function test_ca4_yape_en_dolares_se_rechaza(): void
    {
        $e = $this->falla(fn () => $this->servicio()->prepararPagos([
            ['method_payment' => 'YAPE', 'moneda_pago' => 'USD', 'monto_pagado' => 50, 'tipo_cambio' => 3.75],
        ], 'PEN'));

        $this->assertSame(422, $e->getStatusCode());
    }

    public function test_moneda_distinta_sin_tipo_de_cambio_se_rechaza(): void
    {
        $e = $this->falla(fn () => $this->servicio()->prepararPagos([
            ['method_payment' => 'EFECTIVO', 'moneda_pago' => 'USD', 'monto_pagado' => 50],
        ], 'PEN'));

        $this->assertSame(422, $e->getStatusCode());
    }

    public function test_metodo_inexistente_o_monto_cero_se_rechaza(): void
    {
        $this->assertSame(422, $this->falla(fn () => $this->servicio()->prepararPagos([
            ['method_payment' => 'BITCOIN', 'amount' => 10],
        ], 'PEN'))->getStatusCode());

        $this->assertSame(422, $this->falla(fn () => $this->servicio()->prepararPagos([
            ['method_payment' => 'EFECTIVO', 'amount' => 0],
        ], 'PEN'))->getStatusCode());
    }

    // ── validarContraSaldo ─────────────────────────────────────────────────────

    public function test_sin_exigir_exacto_puede_faltar_pero_no_pasarse(): void
    {
        // Ventas (retail): pago parcial permitido; pasarse no.
        $pagos = $this->servicio()->prepararPagos([['method_payment' => 'YAPE', 'amount' => 50]], 'PEN');
        $this->assertNull($this->servicio()->validarContraSaldo($pagos, 100, 'PEN', exacto: false));

        $deMas = $this->servicio()->prepararPagos([['method_payment' => 'YAPE', 'amount' => 150]], 'PEN');
        $this->assertSame(422, $this->falla(fn () => $this->servicio()->validarContraSaldo($deMas, 100, 'PEN', exacto: false))->getStatusCode());
    }

    public function test_exacto_tiene_que_cubrir_el_saldo(): void
    {
        $pagos = $this->servicio()->prepararPagos([['method_payment' => 'YAPE', 'amount' => 90]], 'PEN');

        $this->assertSame(422, $this->falla(fn () => $this->servicio()->validarContraSaldo($pagos, 100, 'PEN', exacto: true))->getStatusCode());
    }

    public function test_ca5_pago_de_mas_en_efectivo_da_vuelto(): void
    {
        $pagos = $this->servicio()->prepararPagos([['method_payment' => 'EFECTIVO', 'amount' => 120]], 'PEN');

        $vuelto = $this->servicio()->validarContraSaldo($pagos, 100, 'PEN', exacto: true);

        $this->assertEquals(['monto' => 20.0, 'moneda' => 'PEN'], $vuelto);
    }

    public function test_ca5_vuelto_en_dolares_usa_el_tipo_de_cambio_del_pago_en_dolares(): void
    {
        // Venta S/ 3,740 pagada con US$ 1,000 a 3.75 = S/ 3,750: sobran S/ 10 = US$ 2.67.
        $pagos = $this->servicio()->prepararPagos([
            ['method_payment' => 'EFECTIVO', 'moneda_pago' => 'USD', 'monto_pagado' => 1000, 'tipo_cambio' => 3.75],
        ], 'PEN');

        $this->assertEquals(['monto' => 10.0, 'moneda' => 'PEN'], $this->servicio()->validarContraSaldo($pagos, 3740, 'PEN', exacto: true, monedaVuelto: 'PEN'));
        $this->assertEquals(['monto' => 2.67, 'moneda' => 'USD'], $this->servicio()->validarContraSaldo($pagos, 3740, 'PEN', exacto: true, monedaVuelto: 'USD'));
    }

    public function test_ca5_pago_de_mas_sin_efectivo_se_rechaza(): void
    {
        $pagos = $this->servicio()->prepararPagos([['method_payment' => 'YAPE', 'amount' => 120]], 'PEN');

        $this->assertSame(422, $this->falla(fn () => $this->servicio()->validarContraSaldo($pagos, 100, 'PEN', exacto: true))->getStatusCode());
    }

    public function test_ca5_vuelto_mayor_que_lo_pagado_en_efectivo_se_rechaza(): void
    {
        // Yape 110 + efectivo 5 = 115 para un saldo de 100: el vuelto (15) no puede salir de 5 en efectivo.
        $pagos = $this->servicio()->prepararPagos([
            ['method_payment' => 'YAPE', 'amount' => 110],
            ['method_payment' => 'EFECTIVO', 'amount' => 5],
        ], 'PEN');

        $this->assertSame(422, $this->falla(fn () => $this->servicio()->validarContraSaldo($pagos, 100, 'PEN', exacto: true))->getStatusCode());
    }

    // ── sesionAbierta / registrar ──────────────────────────────────────────────

    public function test_ca6_sin_caja_abierta_422(): void
    {
        $cajero = $this->usuario(['cash.open_session']);

        $e = $this->falla(fn () => $this->servicio()->sesionAbierta($cajero));

        $this->assertSame(422, $e->getStatusCode());
        $this->assertStringContainsString('Abre tu caja para cobrar', $e->getMessage());
    }

    public function test_ca3_registrar_guarda_el_pago_y_la_caja_en_la_moneda_del_pago(): void
    {
        $cajero = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($cajero);
        $venta = Sale::factory()->create(['currency' => 'PEN', 'total' => 3750]);
        $pagos = $this->servicio()->prepararPagos([
            ['method_payment' => 'EFECTIVO', 'moneda_pago' => 'USD', 'monto_pagado' => 1000, 'tipo_cambio' => 3.75, 'nro_operacion' => 'OP-1'],
        ], 'PEN');

        $this->servicio()->registrar($venta, $pagos, $this->servicio()->sesionAbierta($cajero), $cajero);

        $pago = SalePayment::where('sale_id', $venta->id)->sole();
        $this->assertEquals(3750.0, (float) $pago->amount, 'amount sigue en la moneda de la venta (R7)');
        $this->assertEquals(1000.0, (float) $pago->monto_pagado);
        $this->assertSame('USD', $pago->moneda_pago);
        $this->assertEquals(3.75, (float) $pago->tipo_cambio);
        $this->assertSame('OP-1', $pago->nro_operacion);

        $mov = CashMovement::where('reference_type', 'sale')->where('reference_id', $venta->id)->sole();
        $this->assertSame($sesion->id, (int) $mov->cash_session_id);
        $this->assertSame('USD', $mov->currency);
        $this->assertEquals(1000.0, (float) $mov->amount);
        $this->assertSame('in', $mov->direction);
    }

    public function test_ca5_registrar_con_vuelto_lo_guarda_en_la_venta_y_saca_el_efectivo(): void
    {
        $cajero = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($cajero);
        $venta = Sale::factory()->create(['currency' => 'PEN', 'total' => 100]);
        $pagos = $this->servicio()->prepararPagos([['method_payment' => 'EFECTIVO', 'amount' => 120]], 'PEN');
        $vuelto = $this->servicio()->validarContraSaldo($pagos, 100, 'PEN', exacto: true);

        $this->servicio()->registrar($venta, $pagos, $sesion, $cajero, $vuelto);

        $venta->refresh();
        $this->assertEquals(20.0, (float) $venta->vuelto_monto);
        $this->assertSame('PEN', $venta->vuelto_moneda);
        $salida = CashMovement::where('reference_type', 'sale')->where('reference_id', $venta->id)->where('direction', 'out')->sole();
        $this->assertSame('sale_change', $salida->type);
        $this->assertEquals(20.0, (float) $salida->amount);
        $this->assertSame('PEN', $salida->currency);
        $this->assertSame('EFECTIVO', PaymentMethod::find($salida->payment_method_id)->code);
    }
}
