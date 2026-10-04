<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Enums\Creditos\OrigenFeriado;
use App\Http\Requests\Creditos\FeriadoRequest;
use App\Models\Creditos\Feriado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Feriados del negocio (00 1.3): cada negocio agrega o quita los suyos. */
class FeriadoController extends ControllerCreditos
{
    public function index(Request $request): JsonResponse
    {
        $anio = (int) $request->query('anio', now()->year);

        return response()->json(['data' => Feriado::whereYear('fecha', $anio)->orderBy('fecha')->get()]);
    }

    public function store(FeriadoRequest $request): JsonResponse
    {
        return response()->json(Feriado::create([...$request->validated(), 'origen' => OrigenFeriado::Propio]), 201);
    }

    public function update(FeriadoRequest $request, int $feriado): JsonResponse
    {
        $modelo = Feriado::findOrFail($feriado);
        $modelo->update($request->validated());

        return response()->json($modelo);
    }

    public function destroy(int $feriado): JsonResponse
    {
        Feriado::findOrFail($feriado)->delete();

        return response()->json(null, 204);
    }
}
