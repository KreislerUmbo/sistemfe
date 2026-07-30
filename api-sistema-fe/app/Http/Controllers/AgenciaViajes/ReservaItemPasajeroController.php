<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Tabla puente: qué pasajero específico va en qué ítem/actividad —
// plan-modulo-cotizaciones-reservas.md §4 (control real de quién hace qué,
// no todos los pax hacen todas las actividades).
class ReservaItemPasajeroController extends Controller
{
    public function index(string $reservaItemId)
    {
        $item = ReservaItem::findOrFail($reservaItemId);

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

    public function destroy(string $id)
    {
        $asignacion = ReservaItemPasajero::findOrFail($id);
        $asignacion->delete();

        return response()->json(['code' => 200, 'message' => 'Asignación eliminada correctamente']);
    }
}
