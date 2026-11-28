<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Http\Controllers\Cash\CashMovementController;
use App\Models\Cash\CashConcept;
use Illuminate\Http\Request;
use Tests\Feature\Compras\ComprasT140TestCase;

// T-140, parte C — el umbral de aprobación de egresos está en soles.
class UmbralAprobacionDolaresTest extends ComprasT140TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cajaConFondo('5000.00', 'PEN');
        $this->cajaConFondo('5000.00', 'USD');
        $this->exigirAprobacionSobre('1000.00');
    }

    private function egresoManual(string $monto, string $moneda): string
    {
        $concepto = CashConcept::firstOrCreate(['name' => 'T140 gasto', 'direction' => 'out'], ['is_active' => true]);
        $pedido = Request::create('/api/cash/movements', 'POST', [
            'type' => 'manual_expense', 'payment_method_id' => $this->efectivo->id, 'amount' => $monto,
            'currency' => $moneda, 'concept_id' => $concepto->id, 'description' => 'Prueba',
        ]);

        return app(CashMovementController::class)->store($pedido)->getData(true)['movement']['status'];
    }

    public function test_egreso_manual_en_dolares(): void // CA21
    {
        $this->ventaSunat('3.7500');

        $this->assertSame('pending_approval', $this->egresoManual('300', 'USD'));
        $this->assertSame('confirmed', $this->egresoManual('250', 'USD'));
    }

    // CA22, cambiado tras /security-review: el mayor entre el T.C. del pago y la venta SUNAT de hoy.
    public function test_pago_usa_el_mayor_tipo_de_cambio(): void // CA22
    {
        $this->ventaSunat('3.2000');

        // 300 × 3.30 (el del pago, mayor) = 990: no pasa el umbral.
        $pago = $this->porCaja('300.00', [], ['moneda' => 'USD', 'tipo_cambio' => '3.3000']);
        $this->assertSame('vigente', $pago->estado);
        $this->assertSame('confirmed', $pago->movimiento->status);
        // 300 × 3.40 = 1,020: el T.C. del pago, mayor, también puede pedir aprobación.
        $this->assertSame('pendiente_aprobacion', $this->porCaja('300.00', [], ['moneda' => 'USD', 'tipo_cambio' => '3.4000'])->estado);
    }

    // /security-review: un T.C. bajo escrito en el pago no saltea la aprobación.
    public function test_tipo_de_cambio_bajo_no_saltea_la_aprobacion(): void
    {
        $this->ventaSunat('3.7500');

        // Con 0.0001 serían S/ 0.03; con la venta SUNAT, 300 × 3.75 = 1,125.
        $this->assertSame('pendiente_aprobacion', $this->porCaja('300.00', [], ['moneda' => 'USD', 'tipo_cambio' => '0.0001'])->estado);
        // 250 × 3.75 = 937.50: confirmado, aunque el pago diga 3.30.
        $this->assertSame('vigente', $this->porCaja('250.00', [], ['moneda' => 'USD', 'tipo_cambio' => '3.3000'])->estado);
    }

    public function test_sin_venta_sunat_el_pago_pide_aprobacion(): void
    {
        $this->assertSame('pendiente_aprobacion', $this->porCaja('1.00', [], ['moneda' => 'USD', 'tipo_cambio' => '3.7500'])->estado);
    }

    public function test_sin_tipo_de_cambio_pide_aprobacion(): void // CA23
    {
        $this->assertSame('pending_approval', $this->egresoManual('1', 'USD'));
        $this->assertSame('confirmed', $this->egresoManual('1000', 'PEN'));
        $this->assertSame('pending_approval', $this->egresoManual('1000.01', 'PEN'));
    }
}
