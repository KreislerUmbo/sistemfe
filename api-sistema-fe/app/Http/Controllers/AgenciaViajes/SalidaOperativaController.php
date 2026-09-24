<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SalidaOperativa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Tablero de despacho — agrupa reserva_items de distintas reservas que
// comparten tour_origen_id + fecha (ver SalidaOperativa/ReservaController::
// engancharSalidaOperativa()). El guía se asigna una vez acá, no por
// reserva; el proveedor de transporte NUNCA se centraliza (sigue en
// reserva_items, editable por reserva).
class SalidaOperativaController extends Controller
{
    public function index(Request $request)
    {
        $query = SalidaOperativa::with(['tourOrigen', 'guia', 'reservaItems.reserva.pasajeros', 'reservaItems.reserva.alternativa.cotizacion.cliente']);

        if ($request->filled('fecha_desde')) {
            $query->where('fecha', '>=', $request->get('fecha_desde'));
        }
        if ($request->filled('fecha_hasta')) {
            $query->where('fecha', '<=', $request->get('fecha_hasta'));
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->get('estado'));
        }

        $salidas = $query->orderBy('fecha')->orderBy('hora')->paginate(20);

        $salidas->getCollection()->transform(fn (SalidaOperativa $s) => $this->resumenSalida($s));

        return response()->json([
            'total' => $salidas->total(),
            'salidas' => $salidas->items(),
        ]);
    }

    public function show(string $id)
    {
        $salida = SalidaOperativa::with(['tourOrigen', 'guia', 'reservaItems.reserva.pasajeros', 'reservaItems.reserva.alternativa.cotizacion.cliente', 'reservaItems.alternativaItem'])
            ->findOrFail($id);

        return response()->json($this->resumenSalida($salida, true));
    }

    public function update(Request $request, string $id)
    {
        $salida = SalidaOperativa::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'guia_id' => 'nullable|integer|exists:guias,id',
            'hora' => 'nullable|date_format:H:i',
            'cupo_maximo' => 'nullable|integer|min:1',
            'vehiculo_descripcion' => 'nullable|string|max:255',
            'notas' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $salida->update($validator->validated());
        $salida->load(['tourOrigen', 'guia', 'reservaItems.reserva.pasajeros', 'reservaItems.reserva.alternativa.cotizacion.cliente']);

        return response()->json(['code' => 200, 'message' => 'Salida actualizada', 'salida' => $this->resumenSalida($salida, true)]);
    }

    public function cancelar(string $id)
    {
        $salida = SalidaOperativa::findOrFail($id);
        $salida->update(['estado' => 'cancelada']);

        // A propósito: NO cancela ni toca ninguna reserva enganchada. Es
        // plata de un cliente, cada una se resuelve a mano — la salida
        // cancelada solo queda como aviso visual en el tablero y en cada
        // reserva enganchada (ver reservas/detalle.vue).
        return response()->json(['code' => 200, 'message' => 'Salida marcada como cancelada. Las reservas enganchadas no se modificaron — revísalas una por una.']);
    }

    // Enganche manual — para ítems que nunca se auto-enganchan: origen_tipo
    // 'guia' (ver ReservaController::engancharSalidaOperativa()), ítems sin
    // tour_origen_id, o cualquier otro caso donde el staff decide agrupar a
    // mano.
    public function attachReservaItem(Request $request, string $id)
    {
        $salida = SalidaOperativa::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'reserva_item_id' => 'required|integer|exists:reserva_items,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $reservaItem = ReservaItem::with('reserva')->findOrFail($request->reserva_item_id);

        // Bug real (auditoría 2026-09-23): este era el único de los 5
        // caminos que tocan salida_operativa_id sin validar nada de la
        // reserva — se podía enganchar a mano un ítem de una reserva
        // CANCELADA, o uno YA FACTURADO (con datos de proveedor/fecha que
        // ya no deberían cambiar), a una salida activa. Mismo criterio que
        // ReservaItemController::destroy()/reasignarMayorista().
        if ($reservaItem->reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede enganchar un ítem de una reserva activa.'], 422);
        }

        $yaFacturado = ReservaVenta::where('reserva_id', $reservaItem->reserva_id)
            ->get()
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_item_ids ?? [])
            ->contains($reservaItem->id);

        if ($yaFacturado) {
            return response()->json(['code' => 422, 'message' => 'No se puede enganchar: este ítem ya fue facturado en una venta de esta reserva.'], 422);
        }

        // Bug real (auditoría 2026-09-23): no capturaba la salida ANTERIOR
        // antes de sobrescribirla — si esa salida vieja se quedaba sin
        // ningún otro ítem, quedaba fantasma en el tablero para siempre
        // (mismo bug de familia ya corregido en detachReservaItem()/
        // ReservaController::cancelar()/reprogramar()/ReservaItemController::
        // destroy(), este era el 5º camino sin cubrir).
        $salidaViejaId = $reservaItem->salida_operativa_id;
        $reservaItem->update(['salida_operativa_id' => $salida->id]);
        SalidaOperativa::eliminarSiQuedoVacia($salidaViejaId);

        return response()->json(['code' => 200, 'message' => 'Ítem enganchado a la salida.']);
    }

    public function detachReservaItem(string $id, string $reservaItemId)
    {
        $reservaItem = ReservaItem::where('salida_operativa_id', $id)->findOrFail($reservaItemId);
        $reservaItem->update(['salida_operativa_id' => null]);

        // Bug real (auditoría 2026-09-22): una salida sin ningún ítem
        // enganchado se quedaba visible para siempre en el tablero, con 0
        // pasajeros — ver SalidaOperativa::eliminarSiQuedoVacia().
        SalidaOperativa::eliminarSiQuedoVacia((int) $id);

        return response()->json(['code' => 200, 'message' => 'Ítem desenganchado de la salida.']);
    }

    private function resumenSalida(SalidaOperativa $s, bool $detalle = false): array
    {
        // Bug real (auditoría 2026-09-23): sumaba pax/reservas de
        // CUALQUIER reserva enganchada, sin importar su estado — una
        // reserva cancelada seguía contando en el tablero de despacho
        // como si fuera a viajar. Mismo criterio que ya usa
        // ReporteOperativoController (excluye 'cancelada').
        $reservasUnicas = $s->reservaItems->pluck('reserva')->filter()
            ->reject(fn ($r) => $r->estado === 'cancelada')
            ->unique('id')->values();

        $base = [
            'id' => $s->id,
            'tour_origen_id' => $s->tour_origen_id,
            'tour_nombre' => $s->tourOrigen?->nombre,
            'fecha' => $s->fecha,
            'hora' => $s->hora,
            'guia_id' => $s->guia_id,
            'guia' => $s->guia,
            'cupo_maximo' => $s->cupo_maximo,
            'vehiculo_descripcion' => $s->vehiculo_descripcion,
            'estado' => $s->estado,
            'notas' => $s->notas,
            'total_pax' => $reservasUnicas->sum(fn ($r) => $r->pasajeros->count()),
            'total_reservas' => $reservasUnicas->count(),
        ];

        if ($detalle) {
            $base['reservas'] = $reservasUnicas->map(fn ($r) => [
                'id' => $r->id,
                'codigo' => $r->alternativa?->cotizacion?->codigo,
                'cliente' => $r->alternativa?->cotizacion?->cliente?->full_name,
                'total_pax' => $r->pasajeros->count(),
            ])->values();
        }

        return $base;
    }
}
