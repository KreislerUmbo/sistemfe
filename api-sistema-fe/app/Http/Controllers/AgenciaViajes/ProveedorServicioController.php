<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Tabla puente proveedor↔destino_servicio (plan-modulo-proveedores.md §2.6,
// Sesión 4) — anidada bajo proveedor, sin ruta propia fuera de ese anidado.
class ProveedorServicioController extends Controller
{
    public function index(string $proveedorId)
    {
        $proveedor = Proveedor::findOrFail($proveedorId);

        $servicios = $proveedor->proveedorServicios()
            ->with(['destinoServicio.destinoAtractivo', 'destinoServicio.servicio'])
            ->get();

        return response()->json(['proveedor_servicios' => $servicios]);
    }

    public function store(Request $request, string $proveedorId)
    {
        $proveedor = Proveedor::findOrFail($proveedorId);

        $validator = Validator::make($request->all(), [
            'destino_servicio_id' => 'required|integer|exists:destino_servicio,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $yaExiste = ProveedorServicio::where('proveedor_id', $proveedor->id)
            ->where('destino_servicio_id', $request->destino_servicio_id)
            ->exists();

        if ($yaExiste) {
            return response()->json([
                'code' => 422,
                'message' => 'Este proveedor ya tiene ese servicio/destino asociado.',
            ], 422);
        }

        $proveedorServicio = ProveedorServicio::create([
            'proveedor_id' => $proveedor->id,
            'destino_servicio_id' => $request->destino_servicio_id,
        ]);

        $proveedorServicio->load(['destinoServicio.destinoAtractivo', 'destinoServicio.servicio']);

        return response()->json([
            'code' => 200,
            'message' => 'Servicio asociado correctamente',
            'proveedor_servicio' => $proveedorServicio,
        ]);
    }

    public function destroy(string $proveedorId, string $servicioId)
    {
        $proveedorServicio = ProveedorServicio::where('proveedor_id', $proveedorId)->findOrFail($servicioId);

        if ($proveedorServicio->proveedorTarifas()->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede quitar: este servicio tiene tarifas cargadas. Elimínalas primero.',
            ], 422);
        }

        $proveedorServicio->delete();

        return response()->json(['code' => 200, 'message' => 'Servicio desasociado correctamente']);
    }
}
