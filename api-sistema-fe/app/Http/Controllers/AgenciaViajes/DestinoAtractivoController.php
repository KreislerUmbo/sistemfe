<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\TourItinerarioItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

// Árbol autoreferenciado de 3 niveles (zona → lugar → atractivo) —
// plan-modulo-tours-catalogo.md §2.
class DestinoAtractivoController extends Controller
{
    public function index(Request $request)
    {
        // ?tipo=zona|lugar|atractivo: lista plana de un solo nivel, para
        // selects (ej. DestinoTreeSelect.vue armando sus propias opciones).
        if ($request->filled('tipo')) {
            $destinos = DestinoAtractivo::where('tipo', $request->get('tipo'))
                ->orderBy('nombre')
                ->get();

            return response()->json(['destinos_atractivos' => $destinos]);
        }

        // Sin filtro: árbol completo anidado (solo 3 niveles posibles, alcanza
        // con 2 niveles de eager load de hijos a partir de cualquier raíz).
        $arbol = DestinoAtractivo::whereNull('parent_id')
            ->with(['hijos' => fn ($q) => $q->orderBy('nombre'), 'hijos.hijos' => fn ($q) => $q->orderBy('nombre')])
            ->orderBy('nombre')
            ->get();

        return response()->json(['destinos_atractivos' => $arbol]);
    }

    public function store(Request $request)
    {
        $validado = $this->validarPayload($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        if ($request->hasFile('fotos')) {
            $validado['fotos'] = $this->subirFotos($request);
        }

        $destino = DestinoAtractivo::create($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Destino/atractivo registrado correctamente',
            'destino_atractivo' => $destino,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $destino = DestinoAtractivo::findOrFail($id);

        $validado = $this->validarPayload($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        if ($request->hasFile('fotos')) {
            $validado['fotos'] = array_merge($destino->fotos ?? [], $this->subirFotos($request));
        }

        $destino->update($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Destino/atractivo actualizado correctamente',
            'destino_atractivo' => $destino,
        ]);
    }

    public function destroy(string $id)
    {
        $destino = DestinoAtractivo::findOrFail($id);

        if ($destino->hijos()->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: tiene destinos/atractivos hijos. Elimínalos primero.',
            ], 422);
        }

        if (DestinoServicio::where('destino_atractivo_id', $destino->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: tiene servicios asociados (destino_servicio).',
            ], 422);
        }

        if (TourItinerarioItem::where('destino_atractivo_id', $destino->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: está referenciado en el itinerario de un tour.',
            ], 422);
        }

        if (GuiaTarifa::where('destino_id', $destino->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: tiene tarifas de guía asociadas.',
            ], 422);
        }

        foreach ($destino->fotos ?? [] as $foto) {
            if (Storage::disk('public')->exists($foto)) {
                Storage::disk('public')->delete($foto);
            }
        }

        $destino->delete();

        return response()->json(['code' => 200, 'message' => 'Destino/atractivo eliminado correctamente']);
    }

    private function subirFotos(Request $request): array
    {
        $paths = [];
        foreach ((array) $request->file('fotos') as $foto) {
            $paths[] = Storage::disk('public')->putFile('destinos-atractivos', $foto);
        }

        return $paths;
    }

    private function validarPayload(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:250',
            'tipo' => 'required|in:zona,lugar,atractivo',
            'parent_id' => 'nullable|integer|exists:destinos_atractivos,id',
            'descripcion' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        return $validator->validated();
    }
}
