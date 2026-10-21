<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

// Tablero de despacho — agrupa reserva_items de distintas reservas que
// comparten tour_origen_id + fecha (ver SalidaOperativa/ReservaController::
// engancharSalidaOperativa()). El guía se asigna una vez acá, no por
// reserva; el proveedor de transporte NUNCA se centraliza (sigue en
// reserva_items, editable por reserva).
//
// Fila 13, fase 2a (§3.6): también agrupa las líneas de tour del modo nuevo
// (una salida privada por reserva si el tour es 'privado'), muestra los
// pasajeros contra el mínimo para salir y maneja los estados programada →
// confirmada → realizada / cancelada (D9).
class SalidaOperativaController extends Controller
{
    private const RELACIONES = [
        'tourOrigen',
        'guia',
        'reservaPrivada',
        'reservaItems.alternativaItem',
        'reservaItems.reserva.pasajeros.pasajeroCatalogo',
        'reservaItems.reserva.alternativa.cotizacion.cliente',
    ];

    public function index(Request $request)
    {
        $query = SalidaOperativa::with(self::RELACIONES);

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
        $salida = SalidaOperativa::with(self::RELACIONES)->findOrFail($id);

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
        $salida->load(self::RELACIONES);

        return response()->json(['code' => 200, 'message' => 'Salida actualizada', 'salida' => $this->resumenSalida($salida, true)]);
    }

    public function cancelar(string $id)
    {
        $salida = SalidaOperativa::findOrFail($id);

        if ($salida->estado === SalidaOperativa::ESTADO_REALIZADA) {
            return response()->json(['code' => 422, 'message' => 'Una salida ya realizada no se puede cancelar.'], 422);
        }

        $salida->update(['estado' => SalidaOperativa::ESTADO_CANCELADA]);

        // A propósito: NO cancela ni toca ninguna reserva enganchada. Es
        // plata de un cliente, cada una se resuelve a mano — la salida
        // cancelada solo queda como aviso visual en el tablero y en cada
        // reserva enganchada (ver reservas/detalle.vue).
        return response()->json(['code' => 200, 'message' => 'Salida marcada como cancelada. Las reservas enganchadas no se modificaron — revísalas una por una.']);
    }

    // PUT salidas-operativas/{id}/estado — fila 13, fase 2a (D9). Avanza o
    // retrocede un paso: programada ⇄ confirmada ⇄ realizada. Cancelar tiene
    // su propio endpoint; una salida cancelada no vuelve atrás por acá.
    public function cambiarEstado(Request $request, string $id)
    {
        $salida = SalidaOperativa::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'estado' => 'required|in:'.implode(',', [
                SalidaOperativa::ESTADO_PROGRAMADA,
                SalidaOperativa::ESTADO_CONFIRMADA,
                SalidaOperativa::ESTADO_REALIZADA,
            ]),
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $nuevo = $request->get('estado');
        $permitidos = [
            SalidaOperativa::ESTADO_PROGRAMADA => [SalidaOperativa::ESTADO_CONFIRMADA],
            SalidaOperativa::ESTADO_CONFIRMADA => [SalidaOperativa::ESTADO_PROGRAMADA, SalidaOperativa::ESTADO_REALIZADA],
            SalidaOperativa::ESTADO_REALIZADA => [SalidaOperativa::ESTADO_CONFIRMADA],
            SalidaOperativa::ESTADO_CANCELADA => [],
        ];

        if (! in_array($nuevo, $permitidos[$salida->estado] ?? [], true)) {
            return response()->json(['code' => 422, 'message' => "Una salida {$salida->estado} no puede pasar a {$nuevo}."], 422);
        }

        // Realizada = ya se hizo: no puede ser una salida de una fecha futura.
        if ($nuevo === SalidaOperativa::ESTADO_REALIZADA && $salida->fecha->gt(HoraPeru::hoy())) {
            return response()->json(['code' => 422, 'message' => 'La salida todavía no llega a su fecha: no se puede marcar como realizada.'], 422);
        }

        $salida->update(['estado' => $nuevo]);
        $salida->load(self::RELACIONES);

        return response()->json(['code' => 200, 'message' => 'Estado de la salida actualizado.', 'salida' => $this->resumenSalida($salida, true)]);
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

        $reservaItem = ReservaItem::with(['reserva', 'alternativaItem'])->findOrFail($request->reserva_item_id);

        if (! in_array($salida->estado, SalidaOperativa::ESTADOS_EN_CURSO, true)) {
            return response()->json(['code' => 422, 'message' => 'Solo se puede enganchar a una salida programada o confirmada.'], 422);
        }

        // Fila 13, fase 2a: una línea de tour solo va a una salida de SU tour,
        // y a una privada solo si es de su misma reserva.
        if ($reservaItem->alternativaItem?->esLineaTour()) {
            if ((int) $salida->tour_origen_id !== (int) $reservaItem->tour_origen_id) {
                return response()->json(['code' => 422, 'message' => 'Un tour solo se engancha a una salida de ese mismo tour.'], 422);
            }
            if ($salida->esPrivada() && (int) $salida->privada_reserva_id !== (int) $reservaItem->reserva_id) {
                return response()->json(['code' => 422, 'message' => 'Esa salida es privada de otra reserva.'], 422);
            }
        }

        // Bug real (auditoría 2026-09-23): este era el único de los 5
        // caminos que tocan salida_operativa_id sin validar nada de la
        // reserva — se podía enganchar a mano un ítem de una reserva
        // CANCELADA, o uno YA FACTURADO (con datos de proveedor/fecha que
        // ya no deberían cambiar), a una salida activa. Mismo criterio que
        // ReservaItemController::destroy()/reasignarMayorista().
        if ($reservaItem->reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede enganchar un ítem de una reserva activa.'], 422);
        }

        $itemsAMover = $this->itemsDeLaMismaCarga($reservaItem);

        $facturados = ReservaVenta::where('reserva_id', $reservaItem->reserva_id)
            ->get()
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_item_ids ?? []);

        if ($itemsAMover->pluck('id')->intersect($facturados)->isNotEmpty()) {
            return response()->json(['code' => 422, 'message' => 'No se puede enganchar: este ítem ya fue facturado en una venta de esta reserva.'], 422);
        }

        // Bug real (auditoría 2026-09-23): no capturaba la salida ANTERIOR
        // antes de sobrescribirla — si esa salida vieja se quedaba sin
        // ningún otro ítem, quedaba fantasma en el tablero para siempre
        // (mismo bug de familia ya corregido en detachReservaItem()/
        // ReservaController::cancelar()/reprogramar()/ReservaItemController::
        // destroy(), este era el 5º camino sin cubrir).
        foreach ($itemsAMover as $item) {
            $salidaViejaId = $item->salida_operativa_id;
            $item->update(['salida_operativa_id' => $salida->id]);
            if ((int) $salidaViejaId !== (int) $salida->id) {
                SalidaOperativa::eliminarSiQuedoVacia($salidaViejaId);
            }
        }

        return response()->json(['code' => 200, 'message' => 'Ítem enganchado a la salida.']);
    }

    public function detachReservaItem(string $id, string $reservaItemId)
    {
        $reservaItem = ReservaItem::with('alternativaItem')->where('salida_operativa_id', $id)->findOrFail($reservaItemId);

        foreach ($this->itemsDeLaMismaCarga($reservaItem) as $item) {
            if ((int) $item->salida_operativa_id === (int) $id) {
                $item->update(['salida_operativa_id' => null]);
            }
        }

        // Bug real (auditoría 2026-09-22): una salida sin ningún ítem
        // enganchado se quedaba visible para siempre en el tablero, con 0
        // pasajeros — ver SalidaOperativa::eliminarSiQuedoVacia().
        SalidaOperativa::eliminarSiQuedoVacia((int) $id);

        return response()->json(['code' => 200, 'message' => 'Ítem desenganchado de la salida.']);
    }

    // Fila 13, fase 2a: las líneas de un tour con IGV mezclado (misma
    // carga_tour) son UN tour en la operación — se enganchan y desenganchan
    // juntas. Cualquier otro ítem se mueve solo.
    private function itemsDeLaMismaCarga(ReservaItem $item): Collection
    {
        if (! $item->carga_tour) {
            return collect([$item]);
        }

        return ReservaItem::where('reserva_id', $item->reserva_id)
            ->where('carga_tour', $item->carga_tour)
            ->get();
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

        $pasajeros = $reservasUnicas->flatMap(fn ($r) => $r->pasajeros);
        $infantes = $pasajeros->where('tipo_pax', 'infante')->count();
        $paxQueCuentan = $pasajeros->count() - $infantes;
        $minimo = $s->tourOrigen?->pax_minimo_salida;

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
            'privada' => $s->esPrivada(),
            'privada_reserva_id' => $s->privada_reserva_id,
            'tiene_lineas_tour' => $s->reservaItems->contains(fn (ReservaItem $i) => $i->alternativaItem?->esLineaTour()),
            'total_pax' => $pasajeros->count(),
            'total_reservas' => $reservasUnicas->count(),
            // Mínimo para salir (§3.6): cuentan adultos y niños; el infante
            // va en brazos y no ocupa lugar.
            'pax' => [
                'adultos' => $pasajeros->where('tipo_pax', 'adulto')->count(),
                'ninos' => $pasajeros->where('tipo_pax', 'nino')->count(),
                'infantes' => $infantes,
                'extranjeros' => $pasajeros->where('residencia', CotizacionPasajero::RESIDENCIA_EXTRANJERO)->count(),
            ],
            'pax_minimo' => $minimo,
            'pax_que_cuentan' => $paxQueCuentan,
            'bajo_minimo' => $minimo !== null && $paxQueCuentan < $minimo,
        ];

        if ($detalle) {
            $base['reservas'] = $reservasUnicas->map(fn ($r) => [
                'id' => $r->id,
                'codigo' => $r->alternativa?->cotizacion?->codigo,
                'cliente' => $r->alternativa?->cotizacion?->cliente?->full_name,
                'total_pax' => $r->pasajeros->count(),
            ])->values();

            $base['pasajeros'] = $reservasUnicas->flatMap(fn ($r) => $r->pasajeros->map(fn ($p) => [
                'id' => $p->id,
                'reserva_id' => $r->id,
                'reserva_codigo' => $r->alternativa?->cotizacion?->codigo,
                'nombre' => $p->nombre ?? $p->pasajeroCatalogo?->nombre,
                'documento' => $p->documento,
                'tipo_pax' => $p->tipo_pax,
                'residencia' => $p->residencia,
                'alimentacion_especial' => $p->alimentacion_especial,
            ]))->values();
        }

        return $base;
    }
}
