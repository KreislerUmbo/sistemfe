<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Servicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Catálogo reutilizable de servicios — plan-modulo-tours-catalogo.md §3.
class ServicioController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search');
        $perPage = (int) $request->input('per_page', 15);

        $servicios = Servicio::when($search, fn ($q) => $q->where('nombre', 'ilike', "%{$search}%"))
            ->orderBy('nombre')
            ->paginate($perPage);

        return response()->json([
            'total' => $servicios->total(),
            'paginate' => $perPage,
            'servicios' => $servicios->items(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:250',
            'tipo_proveedor_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $validado['nombre'] = trim($validado['nombre']);

        // 29-ago-2026 — diagnóstico UX/técnico del flujo de servicios en
        // Destinos: sin esto, "Traslado"/"traslado"/"Traslado " (espacio)
        // se creaban como 3 filas distintas del mismo catálogo compartido
        // — la causa real detrás de que la mayoría de servicios terminen
        // en "Sin categoría" (desglose de paquetes/detalle.vue). Bloqueo
        // duro solo en coincidencia EXACTA (case-insensitive, trim) — un
        // parecido pero distinto ("Traslado ida y vuelta") sigue
        // permitido, para eso está la sugerencia visual del buscador
        // unificado en el frontend, no un bloqueo acá.
        if ($this->existeNombreDuplicado($validado['nombre'])) {
            return response()->json([
                'code' => 422,
                'message' => "Ya existe un servicio llamado \"{$validado['nombre']}\" en el catálogo — usá ese en vez de crear uno nuevo.",
            ], 422);
        }

        $servicio = Servicio::create($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Servicio registrado correctamente',
            'servicio' => $servicio,
        ]);
    }

    public function show(string $id)
    {
        return response()->json(['servicio' => Servicio::findOrFail($id)]);
    }

    public function update(Request $request, string $id)
    {
        $servicio = Servicio::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:250',
            'tipo_proveedor_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $validado['nombre'] = trim($validado['nombre']);

        if ($this->existeNombreDuplicado($validado['nombre'], $servicio->id)) {
            return response()->json([
                'code' => 422,
                'message' => "Ya existe otro servicio llamado \"{$validado['nombre']}\" en el catálogo.",
            ], 422);
        }

        $servicio->update($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Servicio actualizado correctamente',
            'servicio' => $servicio,
        ]);
    }

    private function existeNombreDuplicado(string $nombre, ?int $exceptoId = null): bool
    {
        return Servicio::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->when($exceptoId, fn ($q) => $q->where('id', '!=', $exceptoId))
            ->exists();
    }

    public function destroy(string $id)
    {
        $servicio = Servicio::findOrFail($id);

        if (DestinoServicio::where('servicio_id', $servicio->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: el servicio está asociado a uno o más destinos.',
            ], 422);
        }

        $servicio->delete();

        return response()->json(['code' => 200, 'message' => 'Servicio eliminado correctamente']);
    }
}
