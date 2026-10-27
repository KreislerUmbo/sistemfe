<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Http\Controllers\Sale\SaleController;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Sale\Sale;
use App\Services\ExpectedCashCalculator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * T-008 (R1): eliminar una venta no enviada a SUNAT anula sus cobros en caja con una corrección.
 * Antes los movimientos quedaban vivos y la caja esperaba al cierre un dinero que ya no
 * correspondía a ninguna venta. Todos los giros.
 */
final class EliminarVentaAnulaCajaTest extends CreditosTestCase
{
    private function metodo(string $code, bool $cuentaEnArqueo): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['code' => $code],
            ['name' => ucfirst(strtolower($code)), 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => $cuentaEnArqueo]
        );
    }

    private function ventaSinEnviar(): Sale
    {
        return Sale::factory()->create(['xml' => null, 'cdr' => null, 'correlativo' => null, 'n_operacion' => null]);
    }

    private function cobro(Sale $venta, CashSession $sesion, PaymentMethod $metodo, float $monto): CashMovement
    {
        return CashMovement::create([
            'cash_session_id' => $sesion->id,
            'type' => 'sale_payment',
            'payment_method_id' => $metodo->id,
            'direction' => 'in',
            'amount' => $monto,
            'reference_type' => 'sale',
            'reference_id' => $venta->id,
            'status' => 'confirmed',
            'created_by' => $sesion->opened_by,
        ]);
    }

    private function eliminar(Sale $venta): array
    {
        return app(SaleController::class)->destroy((string) $venta->id)->getData(true);
    }

    public function test_eliminar_corrige_el_cobro_y_el_esperado_vuelve_atras(): void
    {
        $cajero = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($cajero);
        $esperadoAntes = app(ExpectedCashCalculator::class)->compute($sesion);

        $venta = $this->ventaSinEnviar();
        $cobro = $this->cobro($venta, $sesion, $this->metodo('EFECTIVO', true), 150);
        $this->assertSame($esperadoAntes + 150, app(ExpectedCashCalculator::class)->compute($sesion));

        $this->assertSame(200, $this->eliminar($venta)['code']);

        $this->assertSoftDeleted('sales', ['id' => $venta->id]);
        $this->assertNotNull($cobro->fresh()->corrected_by);
        $this->assertSame(1, CashMovement::where('corrected_movement_id', $cobro->id)->where('type', 'correction')->count());
        $this->assertSame($esperadoAntes, app(ExpectedCashCalculator::class)->compute($sesion));
    }

    public function test_pago_mixto_corrige_los_dos_movimientos(): void
    {
        $cajero = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($cajero);
        $venta = $this->ventaSinEnviar();
        $efectivo = $this->cobro($venta, $sesion, $this->metodo('EFECTIVO', true), 200);
        $yape = $this->cobro($venta, $sesion, $this->metodo('YAPE', false), 300);

        $this->assertSame(200, $this->eliminar($venta)['code']);

        $this->assertNotNull($efectivo->fresh()->corrected_by);
        $this->assertNotNull($yape->fresh()->corrected_by);
        $this->assertSame(2, CashMovement::whereIn('corrected_movement_id', [$efectivo->id, $yape->id])->count());
    }

    public function test_sesion_cerrada_sin_permiso_403_y_no_se_elimina(): void
    {
        $cajero = $this->usuario(['cash.open_session']);
        $sesionVieja = $this->abrirCaja($cajero);
        $venta = $this->ventaSinEnviar();
        $cobro = $this->cobro($venta, $sesionVieja, $this->metodo('EFECTIVO', true), 80);
        $sesionVieja->update(['status' => 'closed', 'closed_at' => now()]);
        $this->abrirCaja($cajero);

        try {
            $this->eliminar($venta);
            $this->fail('Se esperaba 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNotSoftDeleted('sales', ['id' => $venta->id]);
        $this->assertNull($cobro->fresh()->corrected_by);
    }

    public function test_sesion_cerrada_con_permiso_la_correccion_cae_en_la_sesion_actual(): void
    {
        $supervisor = $this->usuario(['cash.open_session', 'cash.close_others_session']);
        $sesionVieja = $this->abrirCaja($supervisor);
        $venta = $this->ventaSinEnviar();
        $cobro = $this->cobro($venta, $sesionVieja, $this->metodo('EFECTIVO', true), 80);
        $sesionVieja->update(['status' => 'closed', 'closed_at' => now()]);
        $sesionActual = $this->abrirCaja($supervisor);

        $this->assertSame(200, $this->eliminar($venta)['code']);

        $correccion = CashMovement::where('corrected_movement_id', $cobro->id)->first();
        $this->assertSame($sesionActual->id, (int) $correccion->cash_session_id);
        $this->assertSame('out', $correccion->direction);
    }

    public function test_sin_caja_abierta_422_y_no_se_elimina_nada(): void
    {
        $cajero = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($cajero);
        $venta = $this->ventaSinEnviar();
        $cobro = $this->cobro($venta, $sesion, $this->metodo('EFECTIVO', true), 60);
        $sesion->update(['status' => 'closed', 'closed_at' => now()]);
        $this->usuario(['cash.open_session', 'cash.close_others_session']); // otro usuario, sin caja

        try {
            $this->eliminar($venta);
            $this->fail('Se esperaba 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertNotSoftDeleted('sales', ['id' => $venta->id]);
        $this->assertNull($cobro->fresh()->corrected_by);
    }

    public function test_venta_sin_cobros_se_elimina_sin_pedir_caja(): void
    {
        $this->usuario(['cash.open_session']); // sin caja abierta
        $venta = $this->ventaSinEnviar();

        $this->assertSame(200, $this->eliminar($venta)['code']);
        $this->assertSoftDeleted('sales', ['id' => $venta->id]);
    }
}
