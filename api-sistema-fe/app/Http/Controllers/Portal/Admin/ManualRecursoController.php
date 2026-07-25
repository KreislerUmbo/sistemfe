<?php

namespace App\Http\Controllers\Portal\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPortal\ManualRecurso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ManualRecursoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $sistemaId = $request->get('sistema_id');

        $recursos = ManualRecurso::with('sistema')
            ->when($sistemaId, fn($q) => $q->where('sistema_id', $sistemaId))
            ->orderBy('orden')
            ->orderBy('id', 'desc')
            ->paginate(15);

        return response()->json([
            'total' => $recursos->total(),
            'paginate' => 15,
            'recursos' => $recursos,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'sistema_id' => 'required|exists:systems,id',
            'titulo' => 'required|string|max:255',
            'categoria' => 'nullable|string|max:50',
            'descripcion' => 'nullable|string',
            'tipo_recurso' => 'nullable|string|max:50',
            'url' => 'nullable|string|max:500',
            'orden' => 'nullable|integer',
            'destacado' => 'boolean',
            'estado' => 'boolean',
        ]);

        $data = $request->only([
            'sistema_id', 'categoria', 'titulo', 'descripcion',
            'tipo_recurso', 'url', 'orden', 'destacado', 'estado',
        ]);

        if ($request->hasFile('archivo')) {
            $data['archivo'] = Storage::putFile('manual_recursos', $request->file('archivo'));
        }

        if ($request->hasFile('miniatura')) {
            $data['miniatura'] = Storage::putFile('manual_recursos/miniaturas', $request->file('miniatura'));
        }

        $recurso = ManualRecurso::create($data);

        return response()->json([
            'code' => 200,
            'message' => 'Recurso guardado correctamente',
            'recurso' => $recurso,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $recurso = ManualRecurso::with('sistema')->findOrFail($id);

        return response()->json([
            'recurso' => $recurso,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $recurso = ManualRecurso::findOrFail($id);

        $request->validate([
            'sistema_id' => 'sometimes|exists:systems,id',
            'titulo' => 'sometimes|string|max:255',
            'categoria' => 'nullable|string|max:50',
            'descripcion' => 'nullable|string',
            'tipo_recurso' => 'nullable|string|max:50',
            'url' => 'nullable|string|max:500',
            'orden' => 'nullable|integer',
            'destacado' => 'boolean',
            'estado' => 'boolean',
        ]);

        $data = $request->only([
            'sistema_id', 'categoria', 'titulo', 'descripcion',
            'tipo_recurso', 'url', 'orden', 'destacado', 'estado',
        ]);

        if ($request->hasFile('archivo')) {
            if ($recurso->archivo && Storage::exists($recurso->archivo)) {
                Storage::delete($recurso->archivo);
            }
            $data['archivo'] = Storage::putFile('manual_recursos', $request->file('archivo'));
        }

        if ($request->hasFile('miniatura')) {
            if ($recurso->miniatura && Storage::exists($recurso->miniatura)) {
                Storage::delete($recurso->miniatura);
            }
            $data['miniatura'] = Storage::putFile('manual_recursos/miniaturas', $request->file('miniatura'));
        }

        $recurso->update($data);

        return response()->json([
            'code' => 200,
            'message' => 'Recurso actualizado correctamente',
            'recurso' => $recurso,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $recurso = ManualRecurso::findOrFail($id);

        if ($recurso->archivo && Storage::exists($recurso->archivo)) {
            Storage::delete($recurso->archivo);
        }
        if ($recurso->miniatura && Storage::exists($recurso->miniatura)) {
            Storage::delete($recurso->miniatura);
        }

        $recurso->delete();

        return response()->json([
            'code' => 200,
            'message' => 'Recurso eliminado correctamente',
        ]);
    }
}
