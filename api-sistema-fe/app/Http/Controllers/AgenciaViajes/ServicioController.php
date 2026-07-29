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

        $servicios = Servicio::when($search, fn ($q) => $q->where('nombre', 'ilike', "%{$search}%"))
            ->orderBy('nombre')
            ->paginate(15);

        return response()->json([
            'total' => $servicios->total(),
            'paginate' => 15,
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

        $servicio = Servicio::create($validator->validated());

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

        $servicio->update($validator->validated());

        return response()->json([
            'code' => 200,
            'message' => 'Servicio actualizado correctamente',
            'servicio' => $servicio,
        ]);
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
