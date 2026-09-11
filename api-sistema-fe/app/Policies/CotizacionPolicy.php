<?php

namespace App\Policies;

use App\Models\AgenciaViajes\Cotizacion;
use App\Models\User;

/**
 * Fase 1b (plan-modulo-menus-y-roles.md §3.3, advertencia 1) — segunda
 * barrera explícita, independiente del Global Scope de
 * EscopablePorVendedor: si algún código futuro consultara con
 * withoutGlobalScopes() o DB::table() crudo (confirmado con grep que HOY
 * no existe ningún caso así, ver docs/planning/agencia-de-viajes/
 * fase1b-roles-permisos.md), esta Policy sigue bloqueando view/update/
 * delete sobre una cotización ajena.
 *
 * `cotizaciones.editar` ya lo exige la ruta (permission: middleware) antes
 * de llegar acá — la Policy solo agrega la dimensión de FILA (¿es SU
 * cotización, o tiene ver_todas?), no vuelve a chequear el permiso base.
 */
class CotizacionPolicy
{
    public function view(User $user, Cotizacion $cotizacion): bool
    {
        return $user->can('cotizaciones.ver_todas') || $cotizacion->vendedor_id === $user->id;
    }

    public function update(User $user, Cotizacion $cotizacion): bool
    {
        return $this->view($user, $cotizacion);
    }

    public function delete(User $user, Cotizacion $cotizacion): bool
    {
        return $this->view($user, $cotizacion);
    }
}
