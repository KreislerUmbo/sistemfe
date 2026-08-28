<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Ítem de reserva copiado de la alternativa aceptada —
// plan-modulo-cotizaciones-reservas.md §4. Costo/precio se leen vía
// alternativaItem(), esta tabla solo guarda lo operativo (guía, proveedor
// que finalmente opera, fecha/hora concretas del servicio).
class ReservaItemController extends Controller
{
    public function update(Request $request, string $id)
    {
        $item = ReservaItem::with('reserva')->findOrFail($id);
        $reserva = $item->reserva;

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede editar un ítem de una reserva activa.'], 422);
        }

        $yaFacturado = ReservaVenta::where('reserva_id', $reserva->id)
            ->get()
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_item_ids ?? [])
            ->contains($item->id);

        if ($yaFacturado) {
            return response()->json(['code' => 422, 'message' => 'No se puede editar: este ítem ya fue facturado en una venta de esta reserva.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'guia_id' => 'nullable|integer|exists:guias,id',
            'proveedor_tarifa_id' => 'nullable|integer|exists:proveedor_tarifas,id',
            'fecha' => 'nullable|date',
            'hora' => 'nullable|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        // A diferencia de AlternativaController::update() (que descarta
        // valores null con array_filter para no pisar campos no enviados),
        // acá SÍ hace falta que un null explícito persista — guia_id/
        // proveedor_tarifa_id/fecha/hora deben poder "vaciarse" sin
        // bloquear nada (§4.2: el guía se asigna un día antes, puede quedar
        // pendiente). validated() ya solo trae las claves realmente
        // enviadas en el request, así que un campo omitido no se toca.
        $validado = $validator->validated();

        // Fase 1 del fix Cotización↔Reserva: cualquier request que traiga
        // 'fecha' explícita (incluso para vaciarla a null) es una edición
        // humana de este ítem puntual — se marca 'manual' para que un
        // recálculo automático futuro (Fase 2, reprogramación) nunca la
        // pise sin decisión explícita. array_key_exists(), no isset(): un
        // 'fecha' => null explícito también cuenta como edición manual.
        $fechaActual = $item->fecha?->toDateString();
        if (array_key_exists('fecha', $validado) && $validado['fecha'] !== $fechaActual) {
            $validado['fecha_origen'] = ReservaItem::FECHA_ORIGEN_MANUAL;
        }

        $item->update($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Ítem actualizado correctamente',
            'reserva_item' => $item->fresh(['alternativaItem', 'guia', 'proveedorTarifa']),
        ]);
    }

    // DELETE reserva-items/{id} — Fase D del plan "Proceso de reserva:
    // facturación + 3 fixes" (2026-08-19). Hasta ahora, sincronizar-items
    // solo podía AGREGAR servicios a una reserva ya aceptada — si un
    // cliente cancelaba una excursión puntual, no había forma soportada de
    // quitarla, solo cancelar la reserva completa.
    //
    // Auditoría de UX/funcionalidad del módulo (2026-08-27): este endpoint
    // existía desde Fase D pero nunca tuvo botón en el frontend que lo
    // llamara (reservaItemService.ts no tenía eliminar()) — quedó conectado
    // recién ahora. De paso se pareja con dos cosas que
    // ReservaPasajeroController::destroy() ya tenía y este no: guard de
    // "mínimo 1" (acá "mínimo 1 ítem", ninguna reserva debería quedar sin
    // ningún ítem) y lockForUpdate() sobre la reserva para cerrar la
    // ventana de carrera con una facturación concurrente
    // (ReservaFacturacionController::store() sí toma ese lock, este destroy
    // no lo tomaba).
    public function destroy(string $id)
    {
        $item = ReservaItem::with('reserva')->findOrFail($id);
        $reserva = $item->reserva;

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede quitar un ítem de una reserva activa.'], 422);
        }

        if ($reserva->items()->count() <= 1) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede quitar: es el último ítem de la reserva.',
            ], 422);
        }

        // Mismo guard que ReservaFacturacionController — un ítem ya
        // cubierto por una venta real no se puede quitar en silencio acá;
        // corregir una reserva ya facturada es la Fase futura de "Camino 1"
        // (Nota de Crédito + venta nueva), fuera de alcance de este borrado.
        $yaFacturado = ReservaVenta::where('reserva_id', $reserva->id)
            ->get()
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_item_ids ?? [])
            ->contains($item->id);

        if ($yaFacturado) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede quitar: este ítem ya fue facturado en una venta de esta reserva.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($item, $reserva) {
                // Re-chequeo bajo lock: mismo criterio que
                // ReservaFacturacionController::store() /
                // ReservaController::actualizarFacturacionExterna() — los
                // checks de arriba corrieron antes de abrir la transacción,
                // así que sin este lock una facturación concurrente podría
                // colarse entre esos checks y el delete real.
                Reserva::where('id', $reserva->id)->lockForUpdate()->firstOrFail();

                $itemLocked = ReservaItem::findOrFail($item->id);

                if ($itemLocked->reserva()->first()->items()->count() <= 1) {
                    throw new HttpException(422, 'No se puede quitar: es el último ítem de la reserva.');
                }

                $yaFacturadoBajoLock = ReservaVenta::where('reserva_id', $reserva->id)
                    ->get()
                    ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_item_ids ?? [])
                    ->contains($itemLocked->id);

                if ($yaFacturadoBajoLock) {
                    throw new HttpException(422, 'No se puede quitar: este ítem ya fue facturado en una venta de esta reserva.');
                }

                // reserva_item_pasajero.reserva_item_id es FK sin
                // cascadeOnDelete() — hay que limpiarla a mano antes de
                // poder borrar el ítem.
                ReservaItemPasajero::where('reserva_item_id', $itemLocked->id)->delete();
                $itemLocked->delete();
            });
        } catch (HttpException $e) {
            return response()->json(['code' => $e->getStatusCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['code' => 200, 'message' => 'Ítem quitado de la reserva correctamente.']);
    }
}
