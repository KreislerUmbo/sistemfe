<?php

namespace App\Services\Cash;

use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Company;
use App\Models\User;
use App\Services\ExpectedCashCalculator;
use App\Services\HoraPeru;
use App\Services\TipoCambio\TipoCambioSunatResolver;
use Brick\Math\BigDecimal;
use Symfony\Component\HttpKernel\Exception\HttpException;

// T-136 — reglas de un egreso (o ingreso) manual de Caja, extraídas de CashMovementController
// para que el pago a proveedor desde Caja cumpla las mismas: sesión abierta propia, método
// activo, aprobación sobre el umbral y efectivo esperado nunca negativo.
class EgresoCajaService
{
    public function __construct(private ExpectedCashCalculator $expectedCashCalculator) {}

    public function sesionAbierta(User $usuario): CashSession
    {
        $sesion = CashSession::where('opened_by', $usuario->id)
            ->where('status', 'open')
            ->first();

        if (! $sesion) {
            throw new HttpException(422, 'No hay una sesión de caja abierta.');
        }

        return $sesion;
    }

    public function metodoPago(int $paymentMethodId): PaymentMethod
    {
        $metodo = PaymentMethod::where('id', $paymentMethodId)->where('is_active', true)->first();

        if (! $metodo) {
            throw new HttpException(422, 'payment_method_id no existe o está inactivo.');
        }

        return $metodo;
    }

    /**
     * Estado con el que nace el movimiento. Aprobación condicional: solo salidas, solo si la
     * empresa la exige y el monto supera el umbral. «No negativo» se valida solo si queda
     * confirmado ahora: uno pendiente se revalida al aprobarse. El umbral está en soles (T-140):
     * `$tipoCambio` es el de la operación en USD; se usa el mayor entre él y la venta SUNAT de hoy.
     */
    public function estadoInicial(CashSession $sesion, PaymentMethod $metodo, string $direccion, float $monto, string $moneda, ?string $tipoCambio = null): string
    {
        $company = Company::first();

        if ($direccion === 'out'
            && $company?->require_expense_approval
            && $company->max_expense_without_approval !== null
            && $this->superaUmbral($monto, $moneda, $tipoCambio, (string) $company->max_expense_without_approval)) {
            return 'pending_approval';
        }

        $this->validarNoNegativo($sesion, [
            ['metodo' => $metodo, 'direction' => $direccion, 'amount' => $monto, 'currency' => $moneda],
        ]);

        return 'confirmed';
    }

    // En USD se mide con el MAYOR entre el T.C. de la operación y la venta SUNAT de hoy (decisión del
    // usuario tras /security-review): el T.C. del pago lo escribe quien paga, y uno bajo
    // (0.0001) dejaba salir un egreso grande sin aprobación. Sin venta SUNAT no se puede contrastar:
    // pide aprobación, aunque la operación traiga su T.C.
    private function superaUmbral(float $monto, string $moneda, ?string $tipoCambio, string $umbral): bool
    {
        $enSoles = BigDecimal::of((string) $monto);
        if ($moneda === 'USD') {
            $sunat = app(TipoCambioSunatResolver::class)->resolverParaFecha(HoraPeru::hoy())?->venta;
            if ($sunat === null) {
                return true;
            }
            $cambio = BigDecimal::of((string) $sunat);
            if ($tipoCambio !== null && BigDecimal::of($tipoCambio)->isGreaterThan($cambio)) {
                $cambio = BigDecimal::of($tipoCambio);
            }
            $enSoles = $enSoles->multipliedBy($cambio);
        }

        return $enSoles->isGreaterThan($umbral);
    }

    // Simula el efecto combinado de una o más operaciones propuestas sobre el efectivo esperado
    // ANTES de persistir nada (regla de integridad #4).
    // $efectos = [['metodo' => PaymentMethod, 'direction' => 'in'|'out', 'amount' => float, 'currency' => 'PEN'|'USD'], ...]
    // T-013: se proyecta cada moneda por separado.
    public function validarNoNegativo(CashSession $sesion, array $efectos): void
    {
        $proyectado = $this->expectedCashCalculator->computePorMoneda($sesion);

        foreach ($efectos as $efecto) {
            if (! $efecto['metodo']->affects_cash_count) {
                continue;
            }
            $moneda = $efecto['currency'] ?? 'PEN';
            $proyectado[$moneda] += $efecto['direction'] === 'in' ? $efecto['amount'] : -$efecto['amount'];
        }

        foreach ($proyectado as $moneda => $total) {
            if (round($total, 2) < 0) {
                throw new HttpException(422, 'Esta operación dejaría el efectivo esperado en negativo ('.$moneda.').');
            }
        }
    }
}
