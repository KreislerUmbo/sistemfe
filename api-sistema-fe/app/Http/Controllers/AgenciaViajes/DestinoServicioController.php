<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\ProveedorServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Tabla puente destino_atractivo↔servicio — plan-modulo-tours-catalogo.md
// §4. Anidada bajo destino, sin ruta propia fuera de ese anidado (salvo
// destroy, que va directo por su propio id).
class DestinoServicioController extends Controller
{
    public function index(string $destinoId)
    {
        $destino = DestinoAtractivo::findOrFail($destinoId);

        $servicios = DestinoServicio::where('destino_atractivo_id', $destino->id)
            ->with('servicio')
            ->get();

        return response()->json(['destino_servicios' => $servicios]);
    }

    public function store(Request $request, string $destinoId)
    {
        $destino = DestinoAtractivo::findOrFail($destinoId);

        $validator = Validator::make($request->all(), [
            'servicio_id' => 'required|integer|exists:servicios,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $yaExiste = DestinoServicio::where('destino_atractivo_id', $destino->id)
            ->where('servicio_id', $request->servicio_id)
            ->exists();

        if ($yaExiste) {
            return response()->json([
                'code' => 422,
                'message' => 'Este destino ya tiene ese servicio asociado.',
            ], 422);
        }

        $destinoServicio = DestinoServicio::create([
            'destino_atractivo_id' => $destino->id,
            'servicio_id' => $request->servicio_id,
        ]);

        $destinoServicio->load('servicio');

        return response()->json([
            'code' => 200,
            'message' => 'Servicio asociado correctamente',
            'destino_servicio' => $destinoServicio,
        ]);
    }

    public function destroy(string $id)
    {
        $destinoServicio = DestinoServicio::findOrFail($id);

        if (ProveedorServicio::where('destino_servicio_id', $destinoServicio->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede quitar: hay proveedores con este servicio/destino asociado.',
            ], 422);
        }

        $destinoServicio->delete();

        return response()->json(['code' => 200, 'message' => 'Servicio desasociado correctamente']);
    }
}
