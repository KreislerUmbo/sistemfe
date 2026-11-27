<?php

namespace App\Http\Middleware;

use App\Services\ModulosTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-135 (D1) — `modulo:<codigo>`: segunda capa de plan-modulo-planes-acceso.md §3.5. El menú no
 * lista lo apagado, y la API además lo bloquea. Va después de `tenant`.
 */
class EnsureModuloActivo
{
    public function __construct(private ModulosTenant $modulos) {}

    public function handle(Request $request, Closure $next, string $codigo): Response
    {
        $tenant = tenant();

        if (! $tenant || ! $this->modulos->activo($tenant, $codigo)) {
            return response()->json(['message' => 'Este módulo no está habilitado'], 403);
        }

        return $next($request);
    }
}
