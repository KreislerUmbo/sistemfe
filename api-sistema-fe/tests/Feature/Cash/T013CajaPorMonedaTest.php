<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Http\Controllers\Cash\CashMovementController;
use App\Http\Controllers\Cash\CashSessionController;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Services\ExpectedCashCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * T-013 (Caja, paso B1): esperado, cierre y cambio de moneda por moneda.
 */
final class T013CajaPorMonedaTest extends CreditosTestCase
{
    private function efectivo(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['code' => 'EFECTIVO'],
            ['name' => 'Efectivo', 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => true]
        );
    }

    private function mov(CashSession $sesion, string $dir, string $moneda, float $monto, string $tipo = 'advance_received'): CashMovement
    {
        return CashMovement::create([
            'cash_session_id' => $sesion->id,
            'type' => $tipo,
            'payment_method_id' => $this->efectivo()->id,
            'direction' => $dir,
            'amount' => $monto,
            'currency' => $moneda,
            'status' => 'confirmed',
            'created_by' => $sesion->opened_by,
        ]);
    }

    private function llamar(string $uri, array $datos, callable $accion)
    {
        $request = Request::create($uri, 'POST', $datos);
        $request->setUserResolver(fn () => Auth::guard('api')->user());
        $this->app->instance('request', $request);

        return $accion($request);
    }

    private function cerrar(array $datos)
    {
        return $this->llamar('/cash/close', $datos, fn ($r) => app(CashSessionController::class)->close($r));
    }

    private function cambiar(array $datos)
    {
        return $this->llamar('/cash/currency-exchange', $datos, fn ($r) => app(CashMovementController::class)->currencyExchange($r));
    }

    /** Sesión con S/ 100 de apertura, venta efectivo S/ 500 y anticipo efectivo US$ 200. */
    private function sesionCa1(): CashSession
    {
        $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja(Auth::guard('api')->user());
        $this->mov($sesion, 'in', 'PEN', 100, 'opening_fund');
        $this->mov($sesion, 'in', 'PEN', 500, 'sale');
        $this->mov($sesion, 'in', 'USD', 200);

        return $sesion;
    }

    public function test_ca1_esperado_por_moneda(): void
    {
        $sesion = $this->sesionCa1();

        $this->assertSame(['PEN' => 600.0, 'USD' => 200.0], app(ExpectedCashCalculator::class)->computePorMoneda($sesion));
        $this->assertSame(600.0, app(ExpectedCashCalculator::class)->compute($sesion));
    }

    public function test_ca2_cierre_con_diferencia_por_moneda(): void
    {
        $sesion = $this->sesionCa1();

        $this->cerrar([
            'counted_cash' => 600, 'counted_cash_usd' => 190, 'difference_reason' => 'Faltan 10 dólares',
            'denominations' => [
                ['denomination' => 100, 'quantity' => 6],
                ['denomination' => 100, 'quantity' => 1, 'currency' => 'USD'],
                ['denomination' => 50, 'quantity' => 1, 'currency' => 'USD'],
            ],
        ]);

        $sesion->refresh();
        $this->assertSame('closed', $sesion->status);
        $this->assertSame('600.00', $sesion->expected_cash);
        $this->assertSame('0.00', $sesion->difference);
        $this->assertSame('200.00', $sesion->expected_cash_usd);
        $this->assertSame('190.00', $sesion->counted_cash_usd);
        $this->assertSame('-10.00', $sesion->difference_usd);
        $porMoneda = $sesion->cashSessionDenominations->groupBy('currency')->map->count()->all();
        $this->assertSame(['PEN' => 1, 'USD' => 2], $porMoneda);
    }

    public function test_la_diferencia_en_dolares_sin_motivo_se_rechaza(): void
    {
        $sesion = $this->sesionCa1();

        try {
            $this->cerrar(['counted_cash' => 600, 'counted_cash_usd' => 190]);
            $this->fail('Debió exigir motivo por la diferencia en dólares.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('US$', $e->getMessage());
        }

        $this->assertSame('open', $sesion->fresh()->status);
    }

    public function test_ca3_cambio_de_moneda(): void
    {
        $sesion = $this->sesionCa1();

        $this->cambiar(['from_currency' => 'USD', 'to_currency' => 'PEN', 'amount' => 200, 'exchange_rate' => 3.74]);

        $this->assertSame(['PEN' => 1348.0, 'USD' => 0.0], app(ExpectedCashCalculator::class)->computePorMoneda($sesion));

        $salida = CashMovement::where('type', 'currency_exchange')->where('direction', 'out')->first();
        $entrada = CashMovement::where('type', 'currency_exchange')->where('direction', 'in')->first();
        $this->assertSame('USD', $salida->currency);
        $this->assertSame('PEN', $entrada->currency);
        $this->assertSame('748.00', $entrada->amount);
        $this->assertSame($entrada->id, (int) $salida->linked_movement_id);
        $this->assertSame($salida->id, (int) $entrada->linked_movement_id);
    }

    public function test_cambio_de_soles_a_dolares_divide_por_el_tipo_de_cambio(): void
    {
        $sesion = $this->sesionCa1();

        $this->cambiar(['from_currency' => 'PEN', 'to_currency' => 'USD', 'amount' => 374, 'exchange_rate' => 3.74]);

        $this->assertSame(['PEN' => 226.0, 'USD' => 300.0], app(ExpectedCashCalculator::class)->computePorMoneda($sesion));
    }

    public function test_el_cambio_no_puede_dejar_una_moneda_en_negativo(): void
    {
        $sesion = $this->sesionCa1();

        try {
            $this->cambiar(['from_currency' => 'USD', 'to_currency' => 'PEN', 'amount' => 250, 'exchange_rate' => 3.74]);
            $this->fail('Debió rechazar el cambio que deja los dólares en negativo.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, CashMovement::where('type', 'currency_exchange')->count());
    }

    public function test_el_cambio_valida_moneda_y_tipo_de_cambio(): void
    {
        $this->sesionCa1();

        foreach ([
            ['from_currency' => 'EUR', 'to_currency' => 'PEN', 'amount' => 10, 'exchange_rate' => 3.7],
            ['from_currency' => 'USD', 'to_currency' => 'USD', 'amount' => 10, 'exchange_rate' => 3.7],
            ['from_currency' => 'USD', 'to_currency' => 'PEN', 'amount' => 10, 'exchange_rate' => 0],
        ] as $datos) {
            try {
                $this->cambiar($datos);
                $this->fail('Debió fallar la validación: ' . json_encode($datos));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_el_cambio_usa_el_permiso_de_movimientos_manuales(): void
    {
        $ruta = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => str_ends_with($r->uri(), 'cash/currency-exchange') && in_array('POST', $r->methods(), true));

        $this->assertNotNull($ruta);
        $this->assertContains('permission:cash.open_session', $ruta->gatherMiddleware());
    }

    public function test_movimiento_manual_en_dolares_y_no_negativo_por_moneda(): void
    {
        $sesion = $this->sesionCa1();
        $concepto = \App\Models\Cash\CashConcept::create(['name' => 'Gasto T013', 'direction' => 'out', 'is_active' => true]);
        $datos = ['type' => 'manual_expense', 'payment_method_id' => $this->efectivo()->id, 'concept_id' => $concepto->id, 'description' => 'x'];

        // 500 USD no alcanza aunque en soles sobre dinero.
        try {
            $this->llamar('/cash/movements', $datos + ['amount' => 500, 'currency' => 'USD'], fn ($r) => app(CashMovementController::class)->store($r));
            $this->fail('Debió rechazar el egreso en dólares sin saldo en dólares.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->llamar('/cash/movements', $datos + ['amount' => 50, 'currency' => 'USD'], fn ($r) => app(CashMovementController::class)->store($r));

        $this->assertSame(['PEN' => 600.0, 'USD' => 150.0], app(ExpectedCashCalculator::class)->computePorMoneda($sesion));
    }

    public function test_apertura_en_dolares_crea_su_fondo(): void
    {
        $this->usuario(['cash.open_session']);
        $caja = \App\Models\Cash\CashRegister::create([
            'branch_id' => \App\Models\Cash\Branch::query()->value('id') ?? \App\Models\Cash\Branch::create(['name' => 'Sede T013', 'is_active' => true])->id,
            'name' => 'Caja T013', 'is_active' => true,
        ]);
        $this->efectivo();

        $this->llamar('/cash/open', ['cash_register_id' => $caja->id, 'opening_amount' => 100, 'opening_amount_usd' => 50],
            fn ($r) => app(CashSessionController::class)->open($r));

        $sesion = CashSession::where('cash_register_id', $caja->id)->first();
        $this->assertSame('50.00', $sesion->opening_amount_usd);
        $this->assertSame(['PEN' => 100.0, 'USD' => 50.0], app(ExpectedCashCalculator::class)->computePorMoneda($sesion));
    }
}
