<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Fase 1b (plan-modulo-menus-y-roles.md §3.3) — scope de fila por vendedor.
 *
 * NO es un Global Scope a propósito — hallazgo real encontrado corriendo la
 * suite completa (no por diseño previo): un Global Scope sobre Cotizacion/
 * Reserva filtra CUALQUIER acceso a esos modelos en TODO el código, incluida
 * la lógica interna que nada tiene que ver con "¿qué ve este usuario en un
 * listado?" — ej. `ReservaController::aceptar()` lee `$alternativa
 * ->cotizacion` para generar el código correlativo de la reserva, una
 * operación de negocio sobre una fila que el usuario YA está operando, no
 * una consulta de visibilidad. Con Global Scope, esa relación devolvía
 * null para cualquier usuario sin `ver_todas` (aunque la fila existiera y
 * fuera legítimamente accesible), rompiendo `aceptar()`/facturación/
 * anticipos con un TypeError — 38 tests reales se rompieron al aplicarlo.
 *
 * Acá `propias()` es un scope LOCAL: cada modelo lo expone, pero SOLO los
 * listados (CotizacionController::index()/ReservaController::index()) lo
 * llaman explícitamente. El resto del código (show/update/delete, acciones
 * internas, servicios) ve la fila real sin filtrar — la protección de fila
 * para esos casos es la Policy (CotizacionPolicy/ReservaPolicy), que ya
 * hacía ese trabajo como "segunda barrera" en el diseño original de §3.3 y
 * ahora es la barrera PRINCIPAL para todo lo que no es listado.
 */
trait EscopablePorVendedor
{
    public function scopePropias(Builder $query): Builder
    {
        $user = auth('api')->user();

        if (! $user || $user->can(static::permisoVerTodas())) {
            return $query;
        }

        static::aplicarFiltroVendedor($query, $user->id);

        return $query;
    }

    abstract protected static function permisoVerTodas(): string;

    abstract protected static function aplicarFiltroVendedor(Builder $query, int $vendedorId): void;
}
