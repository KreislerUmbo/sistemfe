<?php

namespace Tests\Feature;

use App\Models\Cash\PaymentMethod;
use App\Models\Company;
use App\Models\Sale\Sale;
use App\Models\Sale\SalePayment;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// T-014: el bloque "FORMA DE PAGO" del comprobante impreso (A4 y ticket, ya existía) muestra el
// nombre de la forma de pago (sin emoji), el n.º de operación, la moneda real del pago con su tipo
// de cambio y el vuelto. Tests escritos por Opus antes de implementar; mismo armado que
// ComprobantePdfCondicionPagoTest (se dibujan las plantillas reales a HTML).
class ComprobantePdfFormaPagoTest extends TestCase
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

        DB::table('roles')->insertOrIgnore(['id' => 1, 'name' => 'rol-pdf-forma-pago', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);

        // Los nombres reales del catálogo traen emoji (PaymentMethodSeeder); DomPDF no los dibuja.
        PaymentMethod::updateOrCreate(['code' => 'EFECTIVO'], ['name' => '💵 Efectivo', 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => true]);
        PaymentMethod::updateOrCreate(['code' => 'YAPE'], ['name' => '📱 Yape', 'is_active' => true, 'sort_order' => 3, 'affects_cash_count' => false]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function cargada(Sale $venta): Sale
    {
        return Sale::with(['client', 'user', 'sale_details.product', 'sale_details.product_categorie', 'sale_payments'])->find($venta->id);
    }

    private function html(Sale $venta, string $plantilla): string
    {
        return view('pdf.'.$plantilla, [
            'venta' => $this->cargada($venta),
            'empresa' => Company::first() ?? new Company(['razon_social' => 'Empresa Test', 'ruc' => '20000000001']),
            'qr' => '',
            'logo' => null,
        ])->render();
    }

    private function pago(Sale $venta, string $metodo, float $monto, array $extra = []): void
    {
        SalePayment::create(array_merge([
            'sale_id' => $venta->id, 'method_payment' => $metodo, 'amount' => $monto, 'date_payment' => '2026-10-29',
            'moneda_pago' => 'PEN', 'monto_pagado' => $monto, 'tipo_cambio' => 1,
        ], $extra));
    }

    // ── Sale::pagosImpresos() / vueltoImpreso(): la única fuente de los dos formatos ──

    public function test_pagos_impresos_con_nombre_sin_emoji_operacion_y_monto(): void
    {
        $venta = Sale::factory()->create(['currency' => 'PEN', 'total' => 500]);
        $this->pago($venta, 'YAPE', 300, ['nro_operacion' => '123456']);
        $this->pago($venta, 'EFECTIVO', 200);

        $filas = $this->cargada($venta)->pagosImpresos();

        $this->assertSame([
            ['forma' => 'Yape', 'operacion' => '123456', 'fecha' => '29/10/2026', 'monto' => 'S/ 300.00', 'tipo_cambio' => null],
            ['forma' => 'Efectivo', 'operacion' => null, 'fecha' => '29/10/2026', 'monto' => 'S/ 200.00', 'tipo_cambio' => null],
        ], $filas->all());
    }

    public function test_pago_en_otra_moneda_muestra_lo_entregado_y_el_tipo_de_cambio(): void
    {
        $venta = Sale::factory()->create(['currency' => 'PEN', 'total' => 3750]);
        $this->pago($venta, 'EFECTIVO', 3750, ['moneda_pago' => 'USD', 'monto_pagado' => 1000, 'tipo_cambio' => 3.75]);

        [$fila] = $this->cargada($venta)->pagosImpresos()->all();

        $this->assertSame('US$ 1,000.00', $fila['monto']);
        $this->assertSame('T.C. 3.75', $fila['tipo_cambio']);
    }

    public function test_un_codigo_que_no_esta_en_el_catalogo_sale_tal_cual(): void
    {
        // Pagos viejos con un método que ya no existe en payment_methods.
        $venta = Sale::factory()->create(['currency' => 'USD', 'total' => 80]);
        $this->pago($venta, 'DEPOSITO BCP', 80, ['moneda_pago' => 'USD']);

        $this->assertSame('DEPOSITO BCP', $this->cargada($venta)->pagosImpresos()->first()['forma']);
    }

    public function test_vuelto_impreso(): void
    {
        $conVuelto = Sale::factory()->create(['currency' => 'PEN', 'total' => 100, 'vuelto_monto' => 50, 'vuelto_moneda' => 'PEN']);
        $sinVuelto = Sale::factory()->create(['currency' => 'PEN', 'total' => 100]);

        $this->assertSame('Vuelto S/ 50.00', $conVuelto->vueltoImpreso());
        $this->assertNull($sinVuelto->vueltoImpreso());
    }

    // ── Las dos plantillas ─────────────────────────────────────────────────────

    public function test_ca1_a4_y_ticket_muestran_nombre_operacion_y_monto(): void
    {
        $venta = Sale::factory()->create(['currency' => 'PEN', 'total' => 500]);
        $this->pago($venta, 'YAPE', 300, ['nro_operacion' => '123456']);
        $this->pago($venta, 'EFECTIVO', 200);

        foreach (['a4', 'ticket80mm'] as $plantilla) {
            $html = $this->html($venta, $plantilla);
            $this->assertStringContainsString('FORMA DE PAGO', $html, $plantilla);
            $this->assertStringContainsString('Yape', $html, $plantilla);
            $this->assertStringContainsString('123456', $html, $plantilla);
            $this->assertStringContainsString('S/ 300.00', $html, $plantilla);
            $this->assertStringNotContainsString('📱', $html, "$plantilla: DomPDF no dibuja emoji");
            $this->assertStringNotContainsString('💵', $html, "$plantilla: DomPDF no dibuja emoji");
        }
    }

    public function test_ca2_sin_pagos_no_sale_el_bloque(): void
    {
        $venta = Sale::factory()->create(['currency' => 'PEN', 'total' => 500]);

        foreach (['a4', 'ticket80mm'] as $plantilla) {
            $this->assertStringNotContainsString('FORMA DE PAGO', $this->html($venta, $plantilla), $plantilla);
        }
    }

    public function test_ca3_a4_y_ticket_muestran_la_moneda_del_pago_y_el_vuelto(): void
    {
        $venta = Sale::factory()->create(['currency' => 'PEN', 'total' => 3700, 'vuelto_monto' => 50, 'vuelto_moneda' => 'PEN']);
        $this->pago($venta, 'EFECTIVO', 3750, ['moneda_pago' => 'USD', 'monto_pagado' => 1000, 'tipo_cambio' => 3.75]);

        foreach (['a4', 'ticket80mm'] as $plantilla) {
            $html = $this->html($venta, $plantilla);
            $this->assertStringContainsString('US$ 1,000.00', $html, $plantilla);
            $this->assertStringContainsString('T.C. 3.75', $html, $plantilla);
            $this->assertStringContainsString('Vuelto S/ 50.00', $html, $plantilla);
        }
    }

    public function test_el_numero_de_operacion_sale_escapado(): void
    {
        $venta = Sale::factory()->create(['currency' => 'PEN', 'total' => 10]);
        $this->pago($venta, 'YAPE', 10, ['nro_operacion' => '<b>x</b>']);

        foreach (['a4', 'ticket80mm'] as $plantilla) {
            $html = $this->html($venta, $plantilla);
            $this->assertStringNotContainsString('<b>x</b>', $html, $plantilla);
            $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html, $plantilla);
        }
    }
}
