<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use App\Services\TipoCambio\TipoCambioSanityService;
use App\Services\TipoCambio\TipoCambioSunatResolver;
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
    public function __construct(
        private TipoCambioSanityService $sanity,
        private TipoCambioSunatResolver $tipoCambioSunatResolver,
    ) {
    }

    public function actual()
    {
        // Última fila de CUALQUIER origen ('dia' o 'agencia') — el
        // vendedor quiere "¿cuál fue el último que alguien tipeó?", sin
        // importar por cuál botón entró en su momento.
        $ultimo = TipoCambioAgencia::orderByDesc('fecha')->orderByDesc('id')->first();

        return response()->json(['code' => 200, 'tipo_cambio_agencia' => $ultimo]);
    }

    // Fase 5 (opcional) del plan de Tipo de Cambio SUNAT — solo lectura,
    // nunca escribe en tipo_cambio_agencia. Sugerencia para prellenar el
    // campo "tipo de cambio del día" al crear una alternativa; el usuario
    // sigue confirmando el valor que realmente se guarda (resolverTipoCambio()
    // en AlternativaController, sin cambios).
    public function sugerenciaSunat()
    {
        $sugerido = $this->tipoCambioSunatResolver->resolverParaFecha(now());

        return response()->json([
            'code' => 200,
            'sugerencia' => $sugerido ? [
                'valor' => $sugerido->venta,
                'fecha' => $sugerido->fecha->toDateString(),
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'valor' => 'required|numeric|min:0.01',
            'origen' => 'required|string|in:dia,agencia',
            // Segundo paso consciente cuando el valor cae fuera del rango
            // de sanidad (2.0-6.0 USD/PEN) — ver TipoCambioSanityService.
            'confirmado' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        if (! $this->sanity->esRazonable((float) $validado['valor']) && ! ($validado['confirmado'] ?? false)) {
            return response()->json([
                'code' => 422,
                'message' => "El valor {$validado['valor']} está fuera del rango esperado para USD/PEN (" . TipoCambioSanityService::RANGO_MINIMO . '-' . TipoCambioSanityService::RANGO_MAXIMO . '). Si es correcto, reenviá con confirmado=true.',
                'requiere_confirmacion' => true,
            ], 422);
        }

        $tipoCambio = TipoCambioAgencia::create([
            'fecha' => now()->toDateString(),
            'origen' => $validado['origen'],
            'valor' => $validado['valor'],
            'registrado_por' => $request->user()->id,
        ]);

        return response()->json(['code' => 200, 'message' => 'Tipo de cambio registrado correctamente', 'tipo_cambio_agencia' => $tipoCambio]);
    }
}
