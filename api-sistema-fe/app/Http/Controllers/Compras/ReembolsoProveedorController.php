<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Models\Compras\ReembolsoProveedor;
use App\Services\Compras\ReembolsoProveedorService;
use Illuminate\Http\Request;

// T-140 — reembolsos del proveedor. Las reglas viven en ReembolsoProveedorService. No hay listado
// (se ven en las fichas de la compra y del pago) ni destroy(): un reembolso se anula.
class ReembolsoProveedorController extends Controller
{
    public function __construct(private ReembolsoProveedorService $service) {}

    public function store(Request $request)
    {
        return $this->respuesta($this->service->registrar($request->all(), auth('api')->user()), 'Reembolso registrado');
    }

    public function anular(Request $request, string $id)
    {
        $reembolso = $this->service->anular(ReembolsoProveedor::findOrFail($id), $request->input('motivo'), auth('api')->user());

        return $this->respuesta($reembolso, 'Reembolso anulado');
    }

    private function respuesta(ReembolsoProveedor $reembolso, string $mensaje)
    {
        $reembolso = ReembolsoProveedor::findOrFail($reembolso->id);

        return response()->json([
            'code' => 200,
            'message' => $mensaje,
            'reembolso' => ReembolsoProveedor::paraFicha($reembolso->newCollection([$reembolso]))['reembolsos'][0],
        ]);
    }
}
