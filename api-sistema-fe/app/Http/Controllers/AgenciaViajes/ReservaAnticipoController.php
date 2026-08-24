<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Advance\AdvanceController;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaAnticipo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Cierra el gap encontrado en la auditoría del módulo Adelantos
// (2026-08-21): `reserva_anticipos` existía desde Sesión 8b (migración
// 2026_07_28_120100_create_reserva_anticipos_table.php) para etiquetar un
// Advance (core) contra una reserva ANTES de que exista el Sale final —
// pero nunca tuvo ningún controller/ruta que la usara. Sin esto,
// ReservaFacturacionController::store() no tenía forma de saber que un
// cliente ya había pagado anticipos hacia una reserva, y generaba la
// venta pidiendo el 100% del total otra vez.
//
// Punto de entrada elegido (decisión de UX con el usuario): cobrar el
// anticipo desde la propia pantalla de la reserva, no desde el módulo
// genérico de Adelantos con etiquetado posterior — reduce el riesgo de
// anticipos huérfanos sin asignar. Internamente sigue reusando
// AdvanceController::store() sin duplicar su lógica (mismo patrón que
// AdvanceController::refund() ya usa para invocar
// NotaElectronicaController::store()).
class ReservaAnticipoController extends Controller
{
    public function store(Request $request, string $id)
    {
        $reserva = Reserva::with('alternativa.cotizacion.cliente')->findOrFail($id);

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede cobrar un anticipo sobre una reserva activa.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'monto' => 'required|numeric|min:0.01',
            'medio_pago' => 'required|string',
            // Tier 1 de Adelantos (2026-08-24): AdvanceController::store()
            // ahora exige elegir tratamiento tributario, ya no gravado fijo.
            'tip_afe_igv' => 'required|string|in:10,20,30',
            'notas' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        // client_id/moneda se DERIVAN de la reserva, nunca del payload —
        // el anticipo es de ESTA reserva, no de un cliente/moneda a
        // elección libre (evita de raíz el guard de moneda distinta que
        // ya blindamos en SaleController::store()/AdvanceApplicationService).
        $cotizacion = $reserva->alternativa->cotizacion;
        $cliente = $cotizacion->cliente;
        $moneda = $reserva->alternativa->moneda_cotizacion;

        $requestAdelanto = new Request([
            'client_id' => $cliente->id,
            'amount' => $validado['monto'],
            'currency' => $moneda,
            'payment_method' => $validado['medio_pago'],
            'tip_afe_igv' => $validado['tip_afe_igv'],
            'notes' => trim("Anticipo — reserva #{$reserva->id} ({$cotizacion->codigo})"
                . ($validado['notas'] ?? '' ? ' — ' . $validado['notas'] : '')),
        ]);

        $reservaAnticipo = DB::transaction(function () use ($requestAdelanto, $reserva, $validado) {
            $respuestaAdelanto = app(AdvanceController::class)->store($requestAdelanto);
            $datosAdelanto = json_decode($respuestaAdelanto->getContent(), true);

            return ReservaAnticipo::create([
                'reserva_id' => $reserva->id,
                'advance_id' => $datosAdelanto['advance_id'],
                'monto_asignado' => $validado['monto'],
                'fecha_asignacion' => now()->toDateString(),
            ]);
        });

        return response()->json([
            'code' => 200,
            'message' => 'Anticipo registrado correctamente para esta reserva.',
            'reserva_anticipo' => $reservaAnticipo->load('advance'),
        ]);
    }

    public function destroy(string $id)
    {
        $reservaAnticipo = ReservaAnticipo::with('advance.applications')->findOrFail($id);

        // El Advance en sí no se toca — sigue existiendo con su plata. Esto
        // solo quita la etiqueta hacia esta reserva. Bloqueado si ya se
        // consumió en una venta real: mismo criterio que el guard de
        // destroy() de venta ya construido para el módulo Adelantos — no
        // se puede destaggear después de usado.
        if ($reservaAnticipo->advance->applications->isNotEmpty()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede quitar: este anticipo ya se aplicó a una venta real.',
            ], 422);
        }

        $reservaAnticipo->delete();

        return response()->json(['code' => 200, 'message' => 'Anticipo desvinculado de la reserva correctamente.']);
    }
}
