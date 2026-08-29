<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\TourItinerarioItem;
use App\Services\AgenciaViajes\FotoUploadService;
use App\Services\StorageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// Árbol autoreferenciado de 3 niveles (zona → lugar → atractivo) —
// plan-modulo-tours-catalogo.md §2.
class DestinoAtractivoController extends Controller
{
    public function __construct(private FotoUploadService $fotoUploadService)
    {
    }

    public function index(Request $request)
    {
        // ?tipo=zona|lugar|atractivo: lista plana de un solo nivel, para
        // selects (ej. DestinoTreeSelect.vue armando sus propias opciones).
        if ($request->filled('tipo')) {
            $destinos = DestinoAtractivo::where('tipo', $request->get('tipo'))
                ->orderBy('nombre')
                ->get();
            $destinos->each(fn ($d) => $this->resolverFotos($d));

            return response()->json(['destinos_atractivos' => $destinos]);
        }

        // Sin filtro: árbol completo anidado (solo 3 niveles posibles, alcanza
        // con 2 niveles de eager load de hijos a partir de cualquier raíz).
        // withCount('destinoServicios') en los 3 niveles (29-ago-2026) —
        // alimenta el filtro "sin servicios asociados" del frontend, sin
        // pedir cada destino uno por uno.
        $arbol = DestinoAtractivo::whereNull('parent_id')
            ->withCount('destinoServicios')
            ->with([
                'hijos' => fn ($q) => $q->orderBy('nombre')->withCount('destinoServicios'),
                'hijos.hijos' => fn ($q) => $q->orderBy('nombre')->withCount('destinoServicios'),
            ])
            ->orderBy('nombre')
            ->get();
        $arbol->each(fn ($d) => $this->resolverFotos($d));

        return response()->json(['destinos_atractivos' => $arbol]);
    }

    // Resuelve 'fotos' (paths relativos → URLs completas) en memoria, sobre
    // el modelo que se va a serializar — nunca antes de guardar, para que la
    // BD siga guardando paths relativos siempre. Recursivo sobre 'hijos' (si
    // viene eager-cargada) porque index() devuelve el árbol anidado.
    private function resolverFotos(DestinoAtractivo $destino): void
    {
        $destino->setAttribute('fotos', StorageUrl::resolveMuchas($destino->fotos ?? []));

        if ($destino->relationLoaded('hijos')) {
            $destino->hijos->each(fn ($hijo) => $this->resolverFotos($hijo));
        }
    }

    public function store(Request $request)
    {
        $validado = $this->validarPayload($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $rechazadas = [];
        if ($request->hasFile('fotos')) {
            try {
                $resultado = $this->fotoUploadService->procesarLote((array) $request->file('fotos'), 'destinos-atractivos', 0);
            } catch (ValidationException $e) {
                return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
            }
            $validado['fotos'] = $resultado['paths'];
            $rechazadas = $resultado['rechazadas'];
        }

        $destino = DestinoAtractivo::create($validado);
        $this->resolverFotos($destino);

        return response()->json([
            'code' => 200,
            'message' => 'Destino/atractivo registrado correctamente',
            'destino_atractivo' => $destino,
            'fotos_rechazadas' => $rechazadas,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $destino = DestinoAtractivo::findOrFail($id);

        $validado = $this->validarPayload($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $rechazadas = [];
        if ($request->hasFile('fotos')) {
            try {
                $resultado = $this->fotoUploadService->procesarLote(
                    (array) $request->file('fotos'),
                    'destinos-atractivos',
                    count($destino->fotos ?? [])
                );
            } catch (ValidationException $e) {
                return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
            }
            $validado['fotos'] = array_merge($destino->fotos ?? [], $resultado['paths']);
            $rechazadas = $resultado['rechazadas'];
        }

        $destino->update($validado);
        $this->resolverFotos($destino);

        return response()->json([
            'code' => 200,
            'message' => 'Destino/atractivo actualizado correctamente',
            'destino_atractivo' => $destino,
            'fotos_rechazadas' => $rechazadas,
        ]);
    }

    // DELETE destinos-atractivos/{id}/fotos — saca UNA foto del array (a
    // diferencia de update(), que solo agrega). Body: {"path": "..."}.
    public function eliminarFoto(Request $request, string $id)
    {
        $destino = DestinoAtractivo::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'path' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        // El frontend puede reenviar tal cual lo que recibió de index()/show()
        // (ya resuelto a URL completa) — normalizamos a path relativo antes
        // de comparar contra lo guardado en BD (que siempre es relativo).
        $path = StorageUrl::relativo($request->get('path'));
        $fotos = $destino->fotos ?? [];

        if (! in_array($path, $fotos, true)) {
            return response()->json([
                'code' => 422,
                'message' => 'La foto indicada no pertenece a este destino/atractivo.',
            ], 422);
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $destino->fotos = array_values(array_diff($fotos, [$path]));
        $destino->save();
        $this->resolverFotos($destino);

        return response()->json([
            'code' => 200,
            'message' => 'Foto eliminada correctamente',
            'destino_atractivo' => $destino,
        ]);
    }

    // PATCH destinos-atractivos/{id}/fotos/orden — reordena el array
    // completo (fotos[0] = portada/thumbnail, ver index.vue/form.vue).
    // Mismo set de paths que ya tenía, sin agregar/quitar — eso vive en
    // update()/eliminarFoto().
    public function ordenarFotos(Request $request, string $id)
    {
        $destino = DestinoAtractivo::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'fotos' => 'required|array',
            'fotos.*' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        // Mismo criterio que eliminarFoto(): el frontend puede reenviar las
        // URLs ya resueltas tal cual las recibió — normalizamos cada una a
        // path relativo antes de comparar/guardar.
        $nuevoOrden = array_map(fn ($p) => StorageUrl::relativo($p), $request->get('fotos'));
        $actuales = $destino->fotos ?? [];

        $nuevoOrdenamiento = $nuevoOrden;
        $actualesOrdenados = $actuales;
        sort($nuevoOrdenamiento);
        sort($actualesOrdenados);

        if ($nuevoOrdenamiento !== $actualesOrdenados) {
            return response()->json([
                'code' => 422,
                'message' => 'El nuevo orden debe contener exactamente las mismas fotos ya guardadas, sin agregar ni quitar.',
            ], 422);
        }

        $destino->fotos = array_values($nuevoOrden);
        $destino->save();
        $this->resolverFotos($destino);

        return response()->json([
            'code' => 200,
            'message' => 'Orden de fotos actualizado correctamente',
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

    private function validarPayload(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:250',
            'tipo' => 'required|in:zona,lugar,atractivo',
            'parent_id' => 'nullable|integer|exists:destinos_atractivos,id',
            'descripcion' => 'nullable|string',
            // Solo valida que sea una imagen válida acá — el límite de 5MB es
            // responsabilidad de FotoUploadService, que rechaza esa foto en
            // particular sin bloquear el resto del lote (a diferencia de un
            // 'max:' acá, que tumbaría la request completa).
            'fotos' => 'nullable|array',
            'fotos.*' => 'image',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        return $validator->validated();
    }
}
