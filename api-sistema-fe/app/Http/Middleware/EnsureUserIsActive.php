<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Corta la sesión de un usuario desactivado (users.state = 2, "Inactivo" en
 * Usuarios). AuthController::login() ya rechaza el login, pero un token emitido
 * antes de desactivarlo seguía valiendo hasta vencer (o renovándose vía
 * auth/refresh) — auditoría de seguridad 08-oct-2026. Usar siempre después de
 * auth:api.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth('api')->user();

        if ($user && (int) $user->state === User::STATE_INACTIVO) {
            throw new HttpException(401, 'Tu usuario está desactivado. Pide a un administrador que lo reactive.');
        }

        return $next($request);
    }
}
