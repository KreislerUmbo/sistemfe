<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureTokenIsCentralGuard
{
    /**
     * Dos capas independientes protegen el aislamiento entre el guard 'central' y el
     * guard 'api' de tenant (comparten el mismo secreto JWT_SECRET):
     *
     * 1. JWT-Auth ya firma cada token con un claim 'prv' (hash de la clase del provider
     *    configurado en el guard que lo emitió). Un token del guard 'api' (provider
     *    'users') tiene un 'prv' distinto al de 'central' (provider 'central_users'), así
     *    que auth('central') lo rechaza con 401 "Unauthenticated" ANTES de que este
     *    middleware llegue a correr — verificado con una prueba real cruzando tokens de
     *    ambos guards (2026-07-20): un id de User de tenant coincidiendo con un id de
     *    CentralUser real (ambos id=1) igual dio 401, nunca autenticó.
     * 2. Este middleware es el refuerzo explícito para el caso en que esa capa no
     *    alcance por sí sola (ej. un cambio futuro que unifique providers, o cualquier
     *    variante de JWT-Auth que deje de firmar 'prv') — exige además el claim propio
     *    'guard' => 'central' (CentralUser::getJWTCustomClaims()) antes de confiar en
     *    auth('central')->user(), mismo patrón que EnsureTokenBelongsToTenant compara
     *    tenant_id. No es la única barrera, es belt-and-suspenders.
     */
    public function handle(Request $request, Closure $next)
    {
        if (auth('central')->payload()->get('guard') !== 'central') {
            throw new HttpException(403, 'El token no pertenece al panel central.');
        }

        return $next($request);
    }
}
