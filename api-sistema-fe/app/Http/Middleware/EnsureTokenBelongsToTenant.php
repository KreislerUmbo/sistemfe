<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureTokenBelongsToTenant
{
    /**
     * Ninguna de las middlewares de identificación de stancl/tenancy valida que el
     * token JWT autenticado pertenezca al tenant resuelto por el subdominio (gap
     * conocido del paquete, archtechx/tenancy#653). Este middleware cierra ese hueco:
     * compara el claim tenant_id del token contra el tenant ya inicializado por
     * InitializeTenancyBySubdomain, en vez de tocar el guard o un bootstrapper.
     */
    public function handle(Request $request, Closure $next)
    {
        if (! tenancy()->initialized) {
            return $next($request);
        }

        $guard = auth('api')->check() ? 'api' : (auth('client')->check() ? 'client' : null);

        if ($guard === null) {
            return $next($request);
        }

        $tokenTenantId = auth($guard)->payload()->get('tenant_id');

        if ($tokenTenantId !== tenant('id')) {
            throw new HttpException(403, 'El token no pertenece a este tenant.');
        }

        return $next($request);
    }
}
