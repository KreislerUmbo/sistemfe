<?php

namespace App\Services;

use App\Models\Cash\CashSession;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Módulo Caja — Fase 5 (plan-modulo-caja.md §9: "un cajero sin cash.view_all
// solo ve su propio historial"). Un solo punto de verdad para la regla de
// visibilidad, reusada por CashSessionController (historial, detalle,
// dashboard, PDFs) y CashMovementController (export Excel) — mismo criterio
// que ExpectedCashCalculator/CreditSummaryCalculator.
class CashVisibilityResolver
{
    public function puedeVerTodas(User $user): bool
    {
        return $user->can('cash.view_all');
    }

    // Resuelve el filtro opened_by efectivo para un listado/export. Sin
    // cash.view_all, el parámetro solicitado se IGNORA (no es un 403, ver
    // Paso 8.1 del prompt de Fase 5) y se fuerza el propio user_id — con
    // cash.view_all, se respeta lo solicitado (null = todos los cajeros).
    public function resolverOpenedBy(User $user, mixed $solicitado): ?int
    {
        if ($this->puedeVerTodas($user)) {
            return $solicitado !== null && $solicitado !== '' ? (int) $solicitado : null;
        }

        return (int) $user->id;
    }

    // Para endpoints de detalle (una sola sesión ya resuelta): acá sí
    // corresponde 403 explícito, no un filtro silencioso — el usuario está
    // pidiendo un recurso puntual que no le pertenece.
    public function verificarAccesoSesion(User $user, CashSession $session): void
    {
        if ($this->puedeVerTodas($user)) {
            return;
        }

        if ((int) $session->opened_by !== (int) $user->id) {
            throw new HttpException(403, 'No tienes permiso para ver la sesión de otro cajero.');
        }
    }
}
