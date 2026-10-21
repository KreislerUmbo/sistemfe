<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaServicio;
use App\Services\AgenciaViajes\SalidaServiciosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Servicios a reservar de una salida — fila 13, fase 2b (§3.6, D10/D11).
// La lista se arma sola desde el Incluye del tour (SalidaServiciosService);
// acá el operador asigna proveedor, ajusta la cantidad, marca "Reservado",
// agrega servicios a mano o vuelve a traer lo nuevo del tour.
class SalidaServicioController extends Controller
{
    private const MENSAJE_CANCELADA = 'La salida está cancelada: sus servicios ya no se modifican.';

    // POST salidas-operativas/{id}/servicios — agregar uno a mano.
    public function store(Request $request, string $salidaId)
    {
        $salida = SalidaOperativa::findOrFail($salidaId);
        if ($salida->estado === SalidaOperativa::ESTADO_CANCELADA) {
            return response()->json(['code' => 422, 'message' => self::MENSAJE_CANCELADA], 422);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'proveedor_id' => 'nullable|integer|exists:proveedores,id',
            'cantidad_manual' => 'nullable|integer|min:0',
            'notas' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $servicio = SalidaServicio::create($validator->validated() + [
            'salida_operativa_id' => $salida->id,
            'estado' => SalidaServicio::ESTADO_POR_RESERVAR,
            'orden' => (int) SalidaServicio::where('salida_operativa_id', $salida->id)->max('orden') + 1,
        ]);

        return response()->json(['code' => 200, 'message' => 'Servicio agregado.', 'servicio' => $servicio->load('proveedor')]);
    }

    // PUT salida-servicios/{id} — proveedor, cantidad, estado, notas.
    public function update(Request $request, string $id)
    {
        $servicio = SalidaServicio::with('salida')->findOrFail($id);
        if ($servicio->salida->estado === SalidaOperativa::ESTADO_CANCELADA) {
            return response()->json(['code' => 422, 'message' => self::MENSAJE_CANCELADA], 422);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'sometimes|required|string|max:255',
            'proveedor_id' => 'sometimes|nullable|integer|exists:proveedores,id',
            // null = vuelve a la cantidad automática (adultos + niños).
            'cantidad_manual' => 'sometimes|nullable|integer|min:0',
            'estado' => 'sometimes|required|in:'.SalidaServicio::ESTADO_POR_RESERVAR.','.SalidaServicio::ESTADO_RESERVADO,
            'notas' => 'sometimes|nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $datos = $validator->validated();

        // No se marca "Reservado" algo que no tiene con quién reservarse.
        $proveedorFinal = array_key_exists('proveedor_id', $datos) ? $datos['proveedor_id'] : $servicio->proveedor_id;
        if (($datos['estado'] ?? null) === SalidaServicio::ESTADO_RESERVADO && ! $proveedorFinal) {
            return response()->json(['code' => 422, 'message' => 'Asigna un proveedor antes de marcar el servicio como reservado.'], 422);
        }

        $servicio->update($datos);

        return response()->json(['code' => 200, 'message' => 'Servicio actualizado.', 'servicio' => $servicio->fresh('proveedor')]);
    }

    // DELETE salida-servicios/{id}
    public function destroy(string $id)
    {
        $servicio = SalidaServicio::with('salida')->findOrFail($id);
        if ($servicio->salida->estado === SalidaOperativa::ESTADO_CANCELADA) {
            return response()->json(['code' => 422, 'message' => self::MENSAJE_CANCELADA], 422);
        }

        $servicio->delete();

        return response()->json([
            'code' => 200,
            'message' => $servicio->paquete_plantilla_item_id
                ? 'Servicio quitado. Si actualizas desde el tour, vuelve a aparecer.'
                : 'Servicio quitado.',
        ]);
    }

    // POST salidas-operativas/{id}/servicios/actualizar — trae lo nuevo del
    // Incluye del tour, sin tocar lo ya asignado.
    public function actualizarDesdeTour(string $salidaId, SalidaServiciosService $servicios)
    {
        $salida = SalidaOperativa::findOrFail($salidaId);
        if ($salida->estado === SalidaOperativa::ESTADO_CANCELADA) {
            return response()->json(['code' => 422, 'message' => self::MENSAJE_CANCELADA], 422);
        }

        $agregados = $servicios->sincronizarDesdeTour($salida);

        return response()->json([
            'code' => 200,
            'agregados' => $agregados,
            'message' => $agregados > 0 ? "Se agregaron {$agregados} servicio(s) del tour." : 'La lista ya tenía todos los servicios del tour.',
        ]);
    }
}
