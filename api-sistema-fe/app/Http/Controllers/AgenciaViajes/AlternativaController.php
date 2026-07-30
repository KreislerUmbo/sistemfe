<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Alternativa de cotización — plan-modulo-cotizaciones-reservas.md §3.1/§3.2.
// Anidada bajo cotización (store), directa por su propio id (update/destroy).
class AlternativaController extends Controller
{
    private const MAX_ALTERNATIVAS_POR_COTIZACION = 5;

    public function store(Request $request, string $cotizacionId)
    {
        $cotizacion = Cotizacion::findOrFail($cotizacionId);

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:100',
            'moneda_cotizacion' => 'required|in:PEN,USD',
            'tipo_cambio_origen' => 'required|in:dia,agencia',
            // Opcional: si se manda, registra un tipo_cambio_agencia NUEVO con
            // este valor (§3.4, "opción de digitar uno nuevo si no está
            // registrado todavía") en vez de reusar el último ya existente.
            'tipo_cambio_valor' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        if ($cotizacion->alternativas()->count() >= self::MAX_ALTERNATIVAS_POR_COTIZACION) {
            return response()->json([
                'code' => 422,
                'message' => 'Esta cotización ya tiene el máximo de ' . self::MAX_ALTERNATIVAS_POR_COTIZACION . ' alternativas permitidas.',
            ], 422);
        }

        $validado = $validator->validated();

        $tipoCambio = $this->resolverTipoCambio($validado['tipo_cambio_origen'], $validado['tipo_cambio_valor'] ?? null, $request);
        if ($tipoCambio instanceof JsonResponse) {
            return $tipoCambio;
        }

        $letra = chr(65 + $cotizacion->alternativas()->count()); // A, B, C...

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacion->id,
            'nombre' => $validado['nombre'] ?: "Alternativa {$letra}",
            'estado' => 'borrador',
            'moneda_cotizacion' => $validado['moneda_cotizacion'],
            'tipo_cambio_aplicado' => $tipoCambio->valor,
            'tipo_cambio_origen' => $validado['tipo_cambio_origen'],
            'total' => 0,
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Alternativa creada correctamente',
            'alternativa' => $alternativa,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $alternativa = Alternativa::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'nombre' => 'nullable|string|max:100',
            'estado' => 'nullable|in:borrador,enviada,aceptada,descartada',
            'descuento_global_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = array_filter($validator->validated(), fn ($v) => $v !== null);

        DB::transaction(function () use ($alternativa, $validado) {
            if (($validado['estado'] ?? null) === 'enviada' && ! $alternativa->fecha_envio) {
                $validado['fecha_envio'] = now();
            }

            $alternativa->update($validado);

            // Aceptar una alternativa descarta automáticamente las demás de
            // la misma cotización — §3.2. Este PUT ya NO dispara creación de
            // reserva (Sesión 11c usa POST alternativas/{id}/aceptar,
            // ReservaController::aceptar(), que reusa descartarOtras() de
            // acá mismo — no se duplica esta lógica).
            if (($validado['estado'] ?? null) === 'aceptada') {
                self::descartarOtras($alternativa);
            }
        });

        return response()->json([
            'code' => 200,
            'message' => 'Alternativa actualizada correctamente',
            'alternativa' => $alternativa->fresh(),
        ]);
    }

    public function destroy(string $id)
    {
        $alternativa = Alternativa::findOrFail($id);
        $alternativa->delete();

        return response()->json(['code' => 200, 'message' => 'Alternativa eliminada correctamente']);
    }

    // Compartido con ReservaController::aceptar() y VentaDirectaController::store()
    // (Sesión 11c) — descartar las demás alternativas de la misma cotización
    // es siempre el mismo movimiento sin importar quién marcó "aceptada".
    public static function descartarOtras(Alternativa $alternativa): void
    {
        Alternativa::where('cotizacion_id', $alternativa->cotizacion_id)
            ->where('id', '!=', $alternativa->id)
            ->update(['estado' => 'descartada']);
    }

    private function resolverTipoCambio(string $origen, ?float $valorNuevo, Request $request): TipoCambioAgencia|JsonResponse
    {
        if ($valorNuevo !== null) {
            return TipoCambioAgencia::create([
                'fecha' => now()->toDateString(),
                'origen' => $origen,
                'valor' => $valorNuevo,
                'registrado_por' => $request->user()->id,
            ]);
        }

        $ultimo = TipoCambioAgencia::where('origen', $origen)->orderByDesc('fecha')->orderByDesc('id')->first();

        if (! $ultimo) {
            return response()->json([
                'code' => 422,
                'message' => "No hay ningún tipo de cambio '{$origen}' registrado todavía — indicá tipo_cambio_valor para registrar uno nuevo.",
            ], 422);
        }

        return $ultimo;
    }
}
