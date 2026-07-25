<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Models\Sale\TipoComprobante;
use Illuminate\Http\Request;

// Catálogo de referencia — seed-only, sin CRUD (ver migración
// create_tipos_comprobante_table). Solo lectura, para poblar selectores.
class TipoComprobanteController extends Controller
{
    // ?disponibles_para_serie=1: filtro exacto que exige el Paso 3 del
    // módulo — activo_greenter=true (tipos fiscales con soporte real en
    // GreenterService) O codigo='NV' (nota de venta, aunque
    // activo_greenter=false). Mismo criterio reusado por
    // SerieComprobanteController::store() en el backend — no confiar solo
    // en que el frontend filtre correctamente.
    public function index(Request $request)
    {
        $query = TipoComprobante::query();

        if ($request->boolean('disponibles_para_serie')) {
            $query->where(function ($q) {
                $q->where('activo_greenter', true)->orWhere('codigo', 'NV');
            });
        }

        return response()->json([
            'tipos_comprobante' => $query->orderBy('codigo')->get(),
        ]);
    }
}
