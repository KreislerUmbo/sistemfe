<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Temporada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Central (compartido por TODO el rubro agencia_viajes, no solo el tenant
// que llama) — plan-modulo-proveedores.md §2.6, Sesión 1. Editar/eliminar
// acá afecta a cualquier otro tenant agencia_viajes que use esta temporada
// — mismo trade-off ya aceptado por el diseño original del catálogo (giro
// compartido, no por tenant). El guard de uso en destroy() solo puede
// verificar proveedor_tarifas DEL TENANT ACTUAL (conexión tenant normal) —
// no hay forma práctica de consultar proveedor_tarifas de otros tenants
// desde esta request; riesgo residual conocido, no resuelto en esta sesión.
class TemporadaController extends Controller
{
    public function index(Request $request)
    {
        $temporadas = Temporada::where('giro', 'agencia_viajes')
            ->with('temporadaOcurrencias')
            ->orderBy('nombre')
            ->get();

        return response()->json(['temporadas' => $temporadas]);
    }

    public function store(Request $request)
    {
        $validado = $this->validarPayload($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $validado['giro'] = 'agencia_viajes';
        $temporada = Temporada::create($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Temporada registrada correctamente',
            'temporada' => $temporada,
        ]);
    }

    public function show(string $id)
    {
        $temporada = Temporada::where('giro', 'agencia_viajes')
            ->with('temporadaOcurrencias')
            ->findOrFail($id);

        return response()->json(['temporada' => $temporada]);
    }

    public function update(Request $request, string $id)
    {
        $temporada = Temporada::where('giro', 'agencia_viajes')->findOrFail($id);

        $validado = $this->validarPayload($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $temporada->update($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Temporada actualizada correctamente',
            'temporada' => $temporada,
        ]);
    }

    public function destroy(string $id)
    {
        $temporada = Temporada::where('giro', 'agencia_viajes')->findOrFail($id);

        if (ProveedorTarifa::where('temporada_id', $temporada->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: hay tarifas de este negocio usando esta temporada.',
            ], 422);
        }

        $temporada->delete();

        return response()->json(['code' => 200, 'message' => 'Temporada eliminada correctamente']);
    }

    private function validarPayload(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:250',
            'tipo' => 'required|in:fija,movil',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        return $validator->validated();
    }
}
