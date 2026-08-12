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

        $proveedoresAsociados = ProveedorServicio::where('destino_servicio_id', $destinoServicio->id)->count();

        if ($proveedoresAsociados > 0) {
            return response()->json([
                'code' => 422,
                'message' => "No se puede quitar: hay {$proveedoresAsociados} proveedor(es) con este servicio/destino asociado. Si la asociación fue un error, usa \"Mover a otro destino\" en su lugar.",
            ], 422);
        }

        $destinoServicio->delete();

        return response()->json(['code' => 200, 'message' => 'Servicio desasociado correctamente']);
    }

    public function mover(Request $request, string $id)
    {
        $destinoServicio = DestinoServicio::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'destino_atractivo_id' => 'required|integer|exists:destinos_atractivos,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $nuevoDestinoId = (int) $request->destino_atractivo_id;

        if ($nuevoDestinoId === $destinoServicio->destino_atractivo_id) {
            return response()->json([
                'code' => 422,
                'message' => 'Ya está asociado a ese destino.',
            ], 422);
        }

        $yaExisteEnDestino = DestinoServicio::where('destino_atractivo_id', $nuevoDestinoId)
            ->where('servicio_id', $destinoServicio->servicio_id)
            ->exists();

        if ($yaExisteEnDestino) {
            return response()->json([
                'code' => 422,
                'message' => 'El destino elegido ya tiene este servicio asociado.',
            ], 422);
        }

        $proveedoresAfectados = ProveedorServicio::where('destino_servicio_id', $destinoServicio->id)->count();

        $destinoServicio->update(['destino_atractivo_id' => $nuevoDestinoId]);
        $destinoServicio->load(['servicio', 'destinoAtractivo']);

        return response()->json([
            'code' => 200,
            'message' => $proveedoresAfectados > 0
                ? "Servicio movido correctamente. {$proveedoresAfectados} proveedor(es)/tarifa(s) asociado(s) se mantienen intactos bajo el nuevo destino."
                : 'Servicio movido correctamente.',
            'destino_servicio' => $destinoServicio,
        ]);
    }
}
