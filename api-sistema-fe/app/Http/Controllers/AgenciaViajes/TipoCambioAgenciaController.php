<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Calculadora de conversión de moneda (07-sep-2026, pedido del usuario) —
// hasta ahora TipoCambioAgencia solo se leía/escribía desde adentro de
// AlternativaController::resolverTipoCambio() (privado, solo al crear una
// alternativa). El vendedor no tenía forma de consultar "¿cuál es el
// último tipo de cambio que registré?" fuera de ese momento puntual — el
// hallazgo real que motivó esto: una alternativa quedó con tipo_cambio_
// aplicado=1.0000 (valor imposible para USD/PEN) sin que nadie lo notara
// hasta auditar la base directo.
//
// actual()/store() son deliberadamente independientes de cualquier
// Alternativa — es una calculadora de bolsillo, no un paso del flujo de
// cotizar. actual() nunca crea nada (a diferencia de resolverTipoCambio(),
// que si no encuentra nada devuelve 422 pidiendo un valor — acá alcanza
// con "null" y el frontend lo maneja como "sin registrar todavía").
class TipoCambioAgenciaController extends Controller
{
    public function actual()
    {
        // Última fila de CUALQUIER origen ('dia' o 'agencia') — el
        // vendedor quiere "¿cuál fue el último que alguien tipeó?", sin
        // importar por cuál botón entró en su momento.
        $ultimo = TipoCambioAgencia::orderByDesc('fecha')->orderByDesc('id')->first();

        return response()->json(['code' => 200, 'tipo_cambio_agencia' => $ultimo]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Sin tope superior a propósito: forzar un rango de sanidad es
            // el punto 4 (todavía pendiente) del plan de moneda del
            // cotizador — acá solo se bloquea lo que nunca puede ser un
            // tipo de cambio real (cero o negativo).
            'valor' => 'required|numeric|min:0.01',
            'origen' => 'required|string|in:dia,agencia',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $tipoCambio = TipoCambioAgencia::create([
            'fecha' => now()->toDateString(),
            'origen' => $validado['origen'],
            'valor' => $validado['valor'],
            'registrado_por' => $request->user()->id,
        ]);

        return response()->json(['code' => 200, 'message' => 'Tipo de cambio registrado correctamente', 'tipo_cambio_agencia' => $tipoCambio]);
    }
}
