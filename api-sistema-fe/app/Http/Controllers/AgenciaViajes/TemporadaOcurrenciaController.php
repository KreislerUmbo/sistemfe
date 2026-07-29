<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Temporada;
use App\Models\AgenciaViajes\TemporadaOcurrencia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Ocurrencia anual concreta de una temporada (central) —
// plan-modulo-proveedores.md §2.6. Anidada bajo temporada.
class TemporadaOcurrenciaController extends Controller
{
    public function index(string $temporadaId)
    {
        $temporada = Temporada::where('giro', 'agencia_viajes')->findOrFail($temporadaId);

        $ocurrencias = $temporada->temporadaOcurrencias()->orderByDesc('anio')->get();

        return response()->json(['temporada_ocurrencias' => $ocurrencias]);
    }

    public function store(Request $request, string $temporadaId)
    {
        $temporada = Temporada::where('giro', 'agencia_viajes')->findOrFail($temporadaId);

        $validator = Validator::make($request->all(), [
            'anio' => 'required|integer|min:2000|max:2100',
            'fecha_desde' => 'required|date',
            'fecha_hasta' => 'required|date|after_or_equal:fecha_desde',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $yaExiste = TemporadaOcurrencia::where('temporada_id', $temporada->id)
            ->where('anio', $request->anio)
            ->exists();

        if ($yaExiste) {
            return response()->json([
                'code' => 422,
                'message' => 'Ya existe una ocurrencia de esta temporada para ese año.',
            ], 422);
        }

        $ocurrencia = TemporadaOcurrencia::create(array_merge(
            $validator->validated(),
            ['temporada_id' => $temporada->id]
        ));

        return response()->json([
            'code' => 200,
            'message' => 'Ocurrencia registrada correctamente',
            'temporada_ocurrencia' => $ocurrencia,
        ]);
    }
}
