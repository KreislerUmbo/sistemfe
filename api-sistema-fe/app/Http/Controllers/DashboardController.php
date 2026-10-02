<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;

/**
 * Inicio (Dashboard) con datos reales del tenant: cada bloque según el giro y los permisos del
 * usuario (DashboardService). Sin permiso propio: quien no tenga ningún permiso de origen recibe
 * la lista de bloques vacía.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->dashboard->inicio(auth('api')->user()));
    }
}
