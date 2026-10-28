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
}
