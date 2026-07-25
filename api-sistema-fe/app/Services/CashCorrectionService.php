<?php

namespace App\Services;

use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Módulo Caja — regla de integridad #1 (plan-modulo-caja.md §5): un
// cash_movement nunca se edita ni se borra a nivel de dato. Este servicio es
// el único punto que sabe CÓMO revertir uno (mismo criterio que
// ExpectedCashCalculator/CreditSummaryCalculator — "un solo punto de
// verdad", extraído en Fase 6 tras encontrar la misma lógica replicada 3
// veces: SaleController::aplicarSincronizacionCaja() (Fase 3),
// CashMovementController::registrarCorreccion() (Fase 4) y
// CreditPaymentController::revertirCashMovementDeRecibo() (Fase 6).
//
// Convención fijada (decisión explícita, no la que tenía SaleController
// antes de esta extracción): reference_type='cash_movement' +
// reference_id=<id del original> — la corrección apunta a QUÉ anula, no al
// recurso de negocio (venta/recibo) que lo originó. Ese recurso sigue
// siendo recuperable navegando desde el movimiento original hasta su
// propio reference_type/reference_id (dos saltos, no uno) — necesario
// porque CashMovementController corrige movimientos manuales que no tienen
// ningún "recurso de negocio" propio distinto del movimiento mismo.
class CashCorrectionService
{
    public function revertirMovimiento(CashMovement $original, User $usuario): CashMovement
    {
        // Backstop defensivo — cada llamador ya filtra por movimientos no
        // corregidos antes de llegar acá, pero un solo punto de verdad
        // también significa que esta regla se hace cumplir una sola vez,
        // no confiada a que los 3 llamadores la repliquen bien siempre.
        if ($original->corrected_by) {
            throw new HttpException(422, "El movimiento #{$original->id} ya fue corregido anteriormente — no se puede corregir dos veces.");
        }

        if ($original->cashSession->status === 'closed' && !$usuario->can('cash.close_others_session')) {
            throw new HttpException(
                403,
                'Este movimiento pertenece a una sesión de caja cerrada — corregirlo requiere permiso de supervisor.'
            );
        }

        $sesionDestino = CashSession::where('opened_by', $usuario->id)
            ->where('status', 'open')
            ->first();

        if (!$sesionDestino) {
            throw new HttpException(422, 'No hay una sesión de caja abierta para registrar la corrección.');
        }

        $correccion = CashMovement::create([
            'cash_session_id'       => $sesionDestino->id,
            'type'                  => 'correction',
            'payment_method_id'     => $original->payment_method_id,
            'direction'             => $original->direction === 'in' ? 'out' : 'in',
            'amount'                => $original->amount,
            'concept_id'            => $original->concept_id,
            'description'           => 'Corrección de movimiento #' . $original->id,
            'reference_type'        => 'cash_movement',
            'reference_id'          => $original->id,
            'corrected_movement_id' => $original->id,
            'status'                => 'confirmed',
            'created_by'            => $usuario->id,
        ]);

        // Nunca se toca el contenido del original — solo se anota que fue corregido.
        $original->update([
            'corrected_by' => $usuario->id,
            'corrected_at' => now(),
        ]);

        return $correccion;
    }
}
