<?php

namespace App\Policies;

use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\User;

/**
 * Fase 1b (plan-modulo-menus-y-roles.md §3.3, advertencia 1) — mismo
 * criterio que CotizacionPolicy. Reserva no tiene vendedor_id propio
 * (hereda de su Cotización padre, ver Reserva::aplicarFiltroVendedor()) —
 * la Policy consulta la misma cadena alternativa->cotizacion.
 *
 * Bug real encontrado al testear (no por asunción): `$reserva->alternativa
 * ->cotizacion` es una relación normal de Eloquent — el Global Scope de
 * Cotizacion (EscopablePorVendedor) se aplica IGUAL al cargarla como
 * relación, así que para un usuario sin ver_todas, cargar la cotización de
 * una reserva AJENA devuelve null (el scope ya la filtró) — la Policy
 * nunca llegaba a leer vendedor_id para poder BLOQUEAR, justo lo opuesto
 * de lo que tiene que hacer. `withoutGlobalScope('vendedor')` acá es a
 * propósito: el chequeo de dueño de la Policy tiene que poder ver la fila
 * real para decidir, sin importar si el scope normal la escondería.
 */
class ReservaPolicy
{
    public function view(User $user, Reserva $reserva): bool
    {
        if ($user->can('reservas.ver_todas')) {
            return true;
        }

        $vendedorId = Cotizacion::withoutGlobalScope('vendedor')
            ->whereHas('alternativas', fn ($q) => $q->where('id', $reserva->alternativa_id))
            ->value('vendedor_id');

        return $vendedorId !== null && $vendedorId === $user->id;
    }

    public function update(User $user, Reserva $reserva): bool
    {
        return $this->view($user, $reserva);
    }

    public function delete(User $user, Reserva $reserva): bool
    {
        return $this->view($user, $reserva);
    }
}
