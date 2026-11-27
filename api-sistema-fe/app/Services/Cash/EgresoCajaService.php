<?php

namespace App\Services\Cash;

use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Company;
use App\Models\User;
use App\Services\ExpectedCashCalculator;
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
     * confirmado ahora: uno pendiente se revalida al aprobarse.
     */
    public function estadoInicial(CashSession $sesion, PaymentMethod $metodo, string $direccion, float $monto, string $moneda): string
    {
        $company = Company::first();

        if ($direccion === 'out'
            && $company?->require_expense_approval
            && $company->max_expense_without_approval !== null
            && $monto > (float) $company->max_expense_without_approval) {
            return 'pending_approval';
        }

        $this->validarNoNegativo($sesion, [
            ['metodo' => $metodo, 'direction' => $direccion, 'amount' => $monto, 'currency' => $moneda],
        ]);

        return 'confirmed';
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
