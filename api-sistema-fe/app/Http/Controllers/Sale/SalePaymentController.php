<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * T-121: rutas retiradas. store()/destroy() cambiaban paid_out/debt/saldo_pendiente de la venta sin
 * movimiento de caja (regla «ningún movimiento de dinero es silencioso»). Desde T-119 el panel guarda
 * los pagos con PATCH sales/{id} (SaleController::update(), que sincroniza la caja) y nadie los llamaba
 * (logs de producción revisados el 23-nov-2026). Las rutas y sus permisos se conservan para que un
 * cliente viejo reciba un 410 claro en vez de un 404.
 */
class SalePaymentController extends Controller
{
    public function index()
    {
        //
    }

    public function store(Request $request): JsonResponse
    {
        return $this->retirada();
    }

    public function show(string $id)
    {
        //
    }

    public function update(Request $request, string $id)
    {
        //
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
