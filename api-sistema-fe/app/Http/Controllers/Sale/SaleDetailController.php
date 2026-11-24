<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * T-121: rutas retiradas. store()/destroy() agregaban o quitaban una línea y cambiaban el total, la
 * deuda y el stock de la venta sin movimiento de caja ni StockVentaService. Las líneas se editan con
 * PATCH sales/{id} (SaleController::update()) y nadie llamaba a estas rutas (logs de producción
 * revisados el 23-nov-2026). Las rutas y sus permisos se conservan: un cliente viejo recibe un 410.
 */
class SaleDetailController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        return $this->retirada();
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->retirada();
    }

    private function retirada(): JsonResponse
    {
        return response()->json([
            'code' => 410,
            'message' => 'Ruta retirada: los pagos y las líneas se editan desde la venta (PATCH sales/{id}).',
        ], 410);
    }
}
