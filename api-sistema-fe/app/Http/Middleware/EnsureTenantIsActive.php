<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureTenantIsActive
{
    /**
     * "Archivado, no borrado" (plan §11.2): un tenant archivado conserva su base y
     * storage intactos (retención legal SUNAT), pero no debe poder loguear ni usar la
     * API. Corre después de 'tenant' (InitializeTenancyBySubdomain) y antes de
     * 'tenant.token'/auth:* — mismo estilo que EnsureTokenBelongsToTenant.
     */
    public function handle(Request $request, Closure $next)
    {
        if (! tenancy()->initialized) {
            return $next($request);
        }

        if (tenant('status') === 'archivado') {
            throw new HttpException(403, 'Este negocio fue archivado y ya no está disponible.');
        }

        return $next($request);
    }
}
