<?php

namespace App\Http\Controllers;

use App\Services\MenuResolver;
use Illuminate\Http\JsonResponse;

// Fase 1a (plan-modulo-menus-y-roles.md §4.2) — GET /me/menu. Respuesta:
// árbol {codigo, label, icono, ruta, hijos} — nunca permiso_requerido/
// modulo_id/giro, son detalles de resolución del backend.
class MenuController extends Controller
{
    public function __construct(private MenuResolver $menuResolver)
    {
    }

    public function miMenu(): JsonResponse
    {
        $user = auth('api')->user();

        return response()->json([
            'menu' => $this->menuResolver->paraUsuario($user, tenant()),
        ]);
    }
}
