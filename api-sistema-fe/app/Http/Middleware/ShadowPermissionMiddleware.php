<?php

namespace App\Http\Middleware;

use App\Models\PermissionShadowLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

// Fase 0c (plan-modulo-menus-y-roles.md §9.1, paso 2 — modo "sombra", Bucket B:
// 31 rutas operativas que Vendedor/Cajero/Contador usan a diario). A diferencia
// de PermissionMiddleware (Spatie, ya gateando Bucket A desde Fase 0b), esta
// clase NUNCA bloquea: solo registra cuando el usuario autenticado no tiene el
// permiso que la ruta exigiría si se gateara de verdad — insumo real para el
// backfill dirigido de la Parte 2, antes de activar el gate real en la Parte 3.
//
// No usa $user->can()/hasPermissionTo() a propósito: esos métodos de Spatie
// lanzan PermissionDoesNotExist si el nombre no existe todavía como fila en la
// tabla `permissions` (varios de los permisos de Bucket B son nuevos, ver
// mapeo en routes/api.php) — un throw ahí rompería la request real que se
// supone que este middleware no debe tocar. getAllPermissions() nunca lanza.
//
// Excluye Super-Admin del log a propósito: bypasea cualquier gate real vía
// Gate::before() (AppServiceProvider) — loguearlo como "le faltaría el
// permiso" sería ruido, nunca representa un bloqueo real que vaya a ocurrir en
// la Parte 3.
class ShadowPermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permiso)
    {
        // Auth::guard('api')->user(), no $request->user('api') — mismo
        // mecanismo real que usa Spatie\Permission\Middleware\
        // PermissionMiddleware::handle() (confirmado leyendo su código: usa
        // Auth::guard($guard)->user(), no el resolver de $request).
        $user = Auth::guard('api')->user();

        if ($user && ! $user->hasRole('Super-Admin')) {
            $tienePermiso = $user->getAllPermissions()->contains('name', $permiso);

            if (! $tienePermiso) {
                $this->registrar($request, $user, $permiso);
            }
        }

        return $next($request);
    }

    private function registrar(Request $request, $user, string $permiso): void
    {
        try {
            PermissionShadowLog::create([
                'tenant_id' => tenancy()->initialized ? tenant('id') : null,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'metodo_http' => $request->method(),
                'ruta' => optional($request->route())->uri() ?? $request->path(),
                'permiso_faltante' => $permiso,
            ]);
        } catch (\Throwable $e) {
            // El modo sombra nunca debe romper una request real por un
            // problema al loguear (ej. migración de permission_shadow_logs
            // no corrida todavía en algún tenant).
            Log::warning('permission_shadow_logs: fallo al registrar', [
                'error' => $e->getMessage(),
                'permiso' => $permiso,
                'ruta' => optional($request->route())->uri(),
            ]);
        }
    }
}
