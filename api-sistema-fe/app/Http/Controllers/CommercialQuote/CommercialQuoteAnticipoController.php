<?php

namespace App\Http\Controllers\CommercialQuote;

use App\Http\Controllers\Advance\AdvanceController;
use App\Http\Controllers\Controller;
use App\Models\CommercialQuote\CommercialQuote;
use App\Models\CommercialQuote\CommercialQuoteAnticipo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Cierra el gap: un cliente puede querer dejar un anticipo para arrancar el
// trabajo de una cotización comercial ANTES de que exista la venta final.
// Mismo patrón exacto que ReservaAnticipoController (Agencia de Viajes,
// hallazgo de auditoría 2026-08-21): el Advance (core) sigue siendo la
// única fuente de verdad del dinero — con su propio comprobante SUNAT real
// (la obligación del IGV nace al recibir el pago, art. 5.1 R.S. 007-99) —
// esta tabla puente solo lo etiqueta contra la cotización, sin duplicar
// nada. La cotización en sí sigue sin ningún efecto fiscal/stock propio;
// el anticipo es una operación real e independiente.
//
// Aplicar el anticipo a la venta final no requiere código nuevo: register.vue
// ya trae los anticipos disponibles del cliente (GET clients/{id}/advances,
// filtrado solo por client_id) apenas se selecciona el cliente al convertir
// la cotización — sin importar contra qué se etiquetó originalmente.
class CommercialQuoteAnticipoController extends Controller
{
    public function store(Request $request, string $id)
    {
        $cotizacion = CommercialQuote::findOrFail($id);

        if ($cotizacion->converted_sale_id) {
            throw new HttpException(422, "Esta cotización ya fue convertida en la venta #{$cotizacion->converted_sale_id} — cobra el saldo desde esa venta o Cuentas por Cobrar, no acá.");
        }

        if (in_array($cotizacion->status, ['anulada', 'rechazada', 'vencida'], true)) {
            throw new HttpException(422, "No se puede cobrar un anticipo sobre una cotización '{$cotizacion->status}'.");
        }

        // El Advance exige un Client real (su propio comprobante SUNAT
        // necesita un DNI/RUC real) — una cotización a un prospecto sin
        // registrar (client_name_free) todavía no puede recibir anticipos.
        if (!$cotizacion->client_id) {
            throw new HttpException(422, "Esta cotización no tiene un cliente registrado. Asigna un cliente real (edítala) antes de cobrar un anticipo — su comprobante SUNAT lo requiere.");
        }

        $validator = Validator::make($request->all(), [
            'monto' => 'required|numeric|min:0.01',
            'medio_pago' => 'required|string',
            // Mismo criterio que ReservaAnticipoController/Tier 1 de
            // Adelantos: sin tratamiento tributario fijo, se elige acá.
            'tip_afe_igv' => 'required|string|in:10,20,30',
            'notas' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        // client_id/moneda se DERIVAN de la cotización, nunca del payload
        // — el anticipo es de ESTA cotización, no de un cliente/moneda a
        // elección libre (mismo criterio que ReservaAnticipoController).
        $requestAdelanto = new Request([
            'client_id' => $cotizacion->client_id,
            'amount' => $validado['monto'],
            'currency' => $cotizacion->currency,
            'payment_method' => $validado['medio_pago'],
            'tip_afe_igv' => $validado['tip_afe_igv'],
            'notes' => trim("Anticipo — cotización {$cotizacion->code}"
                . ($validado['notas'] ?? '' ? ' — ' . $validado['notas'] : '')),
        ]);

        $cotizacionAnticipo = DB::transaction(function () use ($requestAdelanto, $cotizacion, $validado) {
            $respuestaAdelanto = app(AdvanceController::class)->store($requestAdelanto);
            $datosAdelanto = json_decode($respuestaAdelanto->getContent(), true);

            return CommercialQuoteAnticipo::create([
                'commercial_quote_id' => $cotizacion->id,
                'advance_id' => $datosAdelanto['advance_id'],
                'monto_asignado' => $validado['monto'],
                'fecha_asignacion' => now()->toDateString(),
            ]);
        });

        return response()->json([
            'code' => 200,
            'message' => 'Anticipo registrado correctamente para esta cotización.',
            'commercial_quote_anticipo' => $cotizacionAnticipo->load('advance'),
        ]);
    }

    public function destroy(string $id)
    {
        $cotizacionAnticipo = CommercialQuoteAnticipo::with('advance.applications')->findOrFail($id);

        // El Advance en sí no se toca — sigue existiendo con su plata. Esto
        // solo quita la etiqueta hacia esta cotización. Bloqueado si ya se
        // consumió en una venta real (mismo criterio que
        // ReservaAnticipoController::destroy()).
        if ($cotizacionAnticipo->advance->applications->isNotEmpty()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede quitar: este anticipo ya se aplicó a una venta real.',
            ], 422);
        }

        $cotizacionAnticipo->delete();

        return response()->json(['code' => 200, 'message' => 'Anticipo desvinculado de la cotización correctamente.']);
    }
}
