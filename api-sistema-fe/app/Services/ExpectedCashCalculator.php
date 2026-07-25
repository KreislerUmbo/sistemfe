<?php

namespace App\Services;

use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;

// Módulo Caja — extraído de CashSessionController (Fase 2) en Fase 4
// (plan-modulo-caja.md §5 regla #4) para que exista un solo punto de verdad:
// tanto el cierre de sesión (Fase 2) como los movimientos manuales (Fase 4,
// validación de "no negativo" en manual_expense/approve) necesitan simular
// "cuánto efectivo debería haber" antes de confirmar una acción — mismo
// criterio que CreditSummaryCalculator ("no duplicar el cálculo, solo
// presentarlo distinto").
class ExpectedCashCalculator
{
    // Solo cuenta movimientos 'confirmed' de métodos que afectan el arqueo
    // físico (payment_methods.affects_cash_count). La versión original en
    // CashSessionController (Fase 2/3) no filtraba por status porque
    // 'pending_approval'/'rejected' no existían todavía en la práctica —
    // ahora sí, así que un egreso pendiente de aprobación NO debe mover el
    // esperado hasta que se confirme, y uno rechazado nunca debe moverlo.
    public function compute(CashSession $session): float
    {
        return (float) CashMovement::where('cash_session_id', $session->id)
            ->where('status', 'confirmed')
            ->whereHas('paymentMethod', function ($q) {
                $q->where('affects_cash_count', true);
            })
            ->get()
            ->reduce(function ($carry, $movimiento) {
                return $carry + ($movimiento->direction === 'in' ? (float) $movimiento->amount : -(float) $movimiento->amount);
            }, 0.0);
    }
}
