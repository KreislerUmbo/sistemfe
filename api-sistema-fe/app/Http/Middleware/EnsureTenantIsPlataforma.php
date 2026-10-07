<?php

namespace App\Http\Middleware;

use App\Services\PlataformaTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureTenantIsPlataforma
{
    /**
     * Escrituras sobre el catálogo central (sistemas, categorías de sistemas,
     * recursos): solo desde el tenant dueño de la plataforma (config/plataforma.php).
     * Va antes del permission:X de la ruta, porque un Super-Admin de cualquier tenant
     * pasa ese permiso por Gate::before. Sin tenancy inicializada no deja pasar.
     */
    public function handle(Request $request, Closure $next)
    {
        if (! PlataformaTenant::esElActual()) {
            throw new HttpException(403, 'Este catálogo es de la plataforma y solo se administra desde UmboSystem.');
        }

        return $next($request);
    }
}
