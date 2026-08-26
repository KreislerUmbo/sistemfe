<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Tabla puente: qué pasajero específico va en qué ítem/actividad —
// plan-modulo-cotizaciones-reservas.md §4 (control real de quién hace qué,
// no todos los pax hacen todas las actividades).
class ReservaItemPasajeroController extends Controller
{
    public function index(string $reservaItemId)
    {
        $item = ReservaItem::with('reserva')->findOrFail($reservaItemId);

        if ($item->reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se pueden asignar pasajeros en una reserva activa.'], 422);
        }

        if ($this->itemYaFacturado($item)) {
            return response()->json(['code' => 422, 'message' => 'No se puede cambiar la asignación: este ítem ya fue facturado.'], 422);
        }

        $asignaciones = ReservaItemPasajero::where('reserva_item_id', $item->id)
            ->with('reservaPasajero')
            ->get();

        return response()->json(['reserva_item_pasajeros' => $asignaciones]);
    }

    public function store(Request $request, string $reservaItemId)
    {
        $item = ReservaItem::findOrFail($reservaItemId);

        $validator = Validator::make($request->all(), [
            'reserva_pasajero_id' => 'required|integer|exists:reserva_pasajeros,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $pasajeroId = $validator->validated()['reserva_pasajero_id'];
        $pasajero = ReservaPasajero::findOrFail($pasajeroId);

        if ($pasajero->reserva_id !== $item->reserva_id) {
            return response()->json([
                'code' => 422,
                'message' => 'Ese pasajero no pertenece a la misma reserva que el ítem.',
            ], 422);
        }

        $asignacion = ReservaItemPasajero::firstOrCreate([
            'reserva_item_id' => $item->id,
            'reserva_pasajero_id' => $pasajeroId,
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Pasajero asignado correctamente',
            'reserva_item_pasajero' => $asignacion,
        ]);
    }

    // Check-in del reporte operativo (Sesión 11d, plan-modulo-cotizaciones-reservas.md
    // §8) — a diferencia de store()/destroy() (asignación pasajero↔ítem propiamente
    // dicha), acá el vínculo puede no existir todavía: la mayoría de reserva_items no
    // tiene vinculo_especifico (cae a "aplica a todos los pasajeros" en el reporte),
    // así que marcar check-in ahí crea el vínculo puntual recién en este momento.
    // Sin bloqueo por itemYaFacturado(): el check-in es operativo, no toca nada
    // financiero.
    public function checkin(Request $request, string $reservaItemId, string $pasajeroId)
    {
        $item = ReservaItem::with(['reserva', 'pasajeros'])->findOrFail($reservaItemId);

        if ($item->reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede marcar check-in en una reserva activa.'], 422);
        }

        $pasajero = ReservaPasajero::findOrFail($pasajeroId);
        if ($pasajero->reserva_id !== $item->reserva_id) {
            return response()->json([
                'code' => 422,
                'message' => 'Ese pasajero no pertenece a la misma reserva que el ítem.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'checkin_realizado' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $checkinRealizado = $validator->validated()['checkin_realizado'];

        // Si el ítem todavía NO tiene ningún vínculo específico, resolverPasajerosDelItem()
        // (ReporteOperativoController) lo trata como "aplica a TODOS los pasajeros de la
        // reserva" (tour grupal). Crear acá el vínculo de un solo pasajero lo "promovería"
        // en silencio a vínculo específico, excluyendo al resto la próxima vez que se
        // cargue el reporte. Para preservar "aplica a todos" en la práctica, la primera vez
        // que se marca check-in sobre un ítem así se materializan los vínculos de TODOS los
        // pasajeros de la reserva (sin marcar check-in en los demás).
        if ($item->pasajeros->isEmpty()) {
            foreach ($item->reserva->pasajeros as $otroPasajero) {
                ReservaItemPasajero::firstOrCreate([
                    'reserva_item_id' => $item->id,
                    'reserva_pasajero_id' => $otroPasajero->id,
                ]);
            }
        }

        $asignacion = ReservaItemPasajero::firstOrCreate([
            'reserva_item_id' => $item->id,
            'reserva_pasajero_id' => $pasajero->id,
        ]);
        $asignacion->update([
            'checkin_realizado' => $checkinRealizado,
            'checkin_hora' => $checkinRealizado ? now() : null,
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Check-in actualizado correctamente',
            'reserva_item_pasajero' => $asignacion,
        ]);
    }

    public function destroy(string $id)
    {
        $asignacion = ReservaItemPasajero::with('reservaItem.reserva')->findOrFail($id);
        $item = $asignacion->reservaItem;

        if ($item->reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se pueden cambiar asignaciones en una reserva activa.'], 422);
        }

        if ($this->itemYaFacturado($item)) {
            return response()->json(['code' => 422, 'message' => 'No se puede cambiar la asignación: este ítem ya fue facturado.'], 422);
        }
        $asignacion->delete();

        return response()->json(['code' => 200, 'message' => 'Asignación eliminada correctamente']);
    }

    private function itemYaFacturado(ReservaItem $item): bool
    {
        return ReservaVenta::where('reserva_id', $item->reserva_id)
            ->get()
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_item_ids ?? [])
            ->contains($item->id);
    }
}
