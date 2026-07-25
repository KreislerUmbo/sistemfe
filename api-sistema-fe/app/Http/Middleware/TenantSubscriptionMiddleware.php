<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantSubscriptionMiddleware
{
    /**
     * Fase B.2.2 (plan-panel-superadmin.md) — bloquea acceso NUEVO cuando un tenant está
     * suspendido por falta de pago (tenants.status === 'suspendido', puesto por
     * tenants:check-overdue-payments). Deliberadamente separado de EnsureTenantIsActive
     * (archivado): son conceptos distintos con mensajes distintos, aunque el patrón sea
     * el mismo — corre después de 'tenant'/'tenant.active' y antes de 'tenant.token'/
     * auth:*, mismo estilo que el resto del pipeline de tenancy.
     *
     * No hay nada que "abortar a medias": el envío a SUNAT en este proyecto es síncrono
     * dentro del propio request (no hay worker de colas, ver
     * plan-panel-superadmin.md "Nota de infraestructura") — no existe un job en cola que
     * este middleware pueda cortar. Bloquear el request nuevo alcanza para cumplir la
     * regla de negocio ("no cortar procesos fiscales a medias"): un envío ya en curso ya
     * pasó este middleware antes de empezar.
     */
    public function handle(Request $request, Closure $next)
    {
        if (! tenancy()->initialized) {
            return $next($request);
        }

        if (tenant('status') === 'suspendido') {
            throw new HttpException(
                403,
                'Este negocio está suspendido por falta de pago. Contacta al administrador de la plataforma.'
            );
        }

        return $next($request);
    }
}
