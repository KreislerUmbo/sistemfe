<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Catálogo simple de guías turísticos — plan-modulo-cotizaciones-reservas.md
// §5.3.
class GuiaController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search');

        $guias = Guia::when($search, fn ($q) => $q->where('nombre', 'ilike', "%{$search}%"))
            ->orderBy('nombre')
            ->paginate(15);

        return response()->json([
            'total' => $guias->total(),
            'paginate' => 15,
            'guias' => $guias->items(),
        ]);
    }

    public function store(Request $request)
    {
        $validado = $this->validarPayload($request);
        if ($validado instanceof \Illuminate\Http\JsonResponse) {
            return $validado;
        }

        $guia = Guia::create($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Guía registrado correctamente',
            'guia' => $guia,
        ]);
    }

    public function show(string $id)
    {
        $guia = Guia::with('guiaTarifas.destino')->findOrFail($id);

        return response()->json(['guia' => $guia]);
    }

    public function update(Request $request, string $id)
    {
        $guia = Guia::findOrFail($id);

        $validado = $this->validarPayload($request);
        if ($validado instanceof \Illuminate\Http\JsonResponse) {
            return $validado;
        }

        $guia->update($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Guía actualizado correctamente',
            'guia' => $guia,
        ]);
    }

    public function destroy(string $id)
    {
        $guia = Guia::findOrFail($id);

        if (ReservaItem::where('guia_id', $guia->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: el guía ya está asignado a una reserva. Desactívalo en su lugar.',
            ], 422);
        }

        if ($guia->guiaTarifas()->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: el guía tiene tarifas cargadas. Elimínalas primero.',
            ], 422);
        }

        $guia->delete();

        return response()->json(['code' => 200, 'message' => 'Guía eliminado correctamente']);
    }

    private function validarPayload(Request $request): array|\Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:250',
            'documento' => 'required|string|max:20',
            'telefono' => 'required|string|max:50',
            'activo' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        return $validator->validated();
    }
}
