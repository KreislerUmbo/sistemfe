<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            $destinoServicioExistente = DestinoServicio::where('destino_atractivo_id', $nuevoDestinoId)
                ->where('servicio_id', $destinoServicio->servicio_id)
                ->first();

            return response()->json([
                'code' => 422,
                'message' => 'El destino elegido ya tiene este servicio asociado.',
                'destino_servicio_existente_id' => $destinoServicioExistente->id,
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

    // Fusiona esta fila (origen, probablemente mal asociada) con otra fila ya
    // existente del MISMO servicio en otro destino. A diferencia de mover(),
    // esto es para cuando el destino correcto de destino YA tiene su propia
    // fila — no se puede simplemente reasignar destino_atractivo_id porque
    // crearía un duplicado. En cambio: los proveedor_servicios de la fila
    // origen pasan a apuntar a la fila destino, y la fila origen se borra
    // (queda sin proveedores, se puede borrar sin bloqueo). Si algún
    // proveedor está enganchado a AMBAS filas a la vez, no se fusiona nada —
    // no hay forma automática de decidir qué tarifa vale, eso lo resuelve
    // una persona a mano (nunca fallback silencioso, mismo principio que el
    // resto del backend).
    public function fusionar(Request $request, string $id)
    {
        $origen = DestinoServicio::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'destino_servicio_destino_id' => 'required|integer|exists:destino_servicio,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $destino = DestinoServicio::findOrFail($request->destino_servicio_destino_id);

        if ($destino->id === $origen->id) {
            return response()->json(['code' => 422, 'message' => 'No podés fusionar un servicio consigo mismo.'], 422);
        }

        if ($destino->servicio_id !== $origen->servicio_id) {
            return response()->json(['code' => 422, 'message' => 'Solo se puede fusionar con una fila del mismo servicio.'], 422);
        }

        $proveedoresOrigen = ProveedorServicio::where('destino_servicio_id', $origen->id)->pluck('proveedor_id');
        $proveedoresDestino = ProveedorServicio::where('destino_servicio_id', $destino->id)->pluck('proveedor_id');
        $conflictivos = $proveedoresOrigen->intersect($proveedoresDestino);

        if ($conflictivos->isNotEmpty()) {
            $nombres = Proveedor::whereIn('id', $conflictivos)->pluck('razon_social')->implode(', ');

            return response()->json([
                'code' => 422,
                'message' => "No se puede fusionar automáticamente: {$nombres} ya está en ambos destinos con tarifas propias — revisalo manualmente antes de fusionar.",
            ], 422);
        }

        $proveedoresMovidos = $proveedoresOrigen->count();

        DB::transaction(function () use ($origen, $destino) {
            ProveedorServicio::where('destino_servicio_id', $origen->id)->update(['destino_servicio_id' => $destino->id]);
            $origen->delete();
        });

        return response()->json([
            'code' => 200,
            'message' => $proveedoresMovidos > 0
                ? "Fusión completada. {$proveedoresMovidos} proveedor(es) pasaron al destino ya existente."
                : 'Fusión completada.',
        ]);
    }
}
