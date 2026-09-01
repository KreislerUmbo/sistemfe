<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Sesión 12f-1 — solo index()/store(), lo mínimo que necesita el botón
// "+ Agregar destino" de 12f-2. update()/destroy() y validación de
// solapamiento de fechas entre destinos (§23.2 de la auditoría) quedan
// fuera a propósito: no hay pantalla de gestión todavía, mismo criterio
// que 12e dejó la UI de Opcionales fuera del alcance de esa sesión.
class AlternativaDestinoController extends Controller
{
    public function index(string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);

        return response()->json(['alternativa_destinos' => $alternativa->destinos]);
    }

    public function store(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);

        // Mismo guard/mensaje ya establecido en AlternativaItemController::
        // reasignarDia()/moverBloque() para "alternativa ya aceptada".
        if ($alternativa->estado === 'aceptada') {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa ya fue aceptada y generó una reserva — usa reprogramar sobre la reserva en vez de agregar destinos acá.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'destino_atractivo_id' => 'nullable|integer|exists:destinos_atractivos,id',
            'destino_texto' => 'required|string|max:250',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $siguienteOrden = (int) ($alternativa->destinos()->max('orden') ?? 0) + 1;

        $destino = AlternativaDestino::create($validado + [
            'alternativa_id' => $alternativa->id,
            'orden' => $siguienteOrden,
        ]);

        return response()->json(['code' => 200, 'message' => 'Destino agregado correctamente', 'alternativa_destino' => $destino]);
    }
}
