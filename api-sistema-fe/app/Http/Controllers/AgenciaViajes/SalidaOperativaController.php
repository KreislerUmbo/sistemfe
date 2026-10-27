<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaServicio;
use App\Models\AgenciaViajes\SalidaVehiculo;
use App\Models\Company;
use App\Services\AgenciaViajes\SalidaEngancheService;
use App\Services\AgenciaViajes\SalidaOperacionService;
use App\Services\HoraPeru;
use App\Services\StorageUrl;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        // Fila 13, fase 2b.
        'servicios.proveedor',
        'vehiculos.proveedor',
        'vehiculos.guia',
        'vehiculos.reservas',
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
            'lugar_recojo' => 'nullable|string|max:255',
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
        // cancelada queda marcada en el tablero y en cada servicio enganchado
        // de la ficha de la reserva ("Salida cancelada", T-042). Se puede
        // reactivar con cambiarEstado() (cancelada → programada).
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
            // T-042 (H35): "Reactivar salida". Solo a mano, nunca sola.
            SalidaOperativa::ESTADO_CANCELADA => [SalidaOperativa::ESTADO_PROGRAMADA],
        ];

        if (! in_array($nuevo, $permitidos[$salida->estado] ?? [], true)) {
            return response()->json(['code' => 422, 'message' => "Una salida {$salida->estado} no puede pasar a {$nuevo}."], 422);
        }

        $reactivar = $salida->estado === SalidaOperativa::ESTADO_CANCELADA;
        if ($reactivar && $salida->fecha->lt(HoraPeru::hoy())) {
            return response()->json(['code' => 422, 'message' => 'La salida ya pasó: no tiene sentido reactivarla.'], 422);
        }

        // Realizada = ya se hizo: no puede ser una salida de una fecha futura.
        if ($nuevo === SalidaOperativa::ESTADO_REALIZADA && $salida->fecha->gt(HoraPeru::hoy())) {
            return response()->json(['code' => 422, 'message' => 'La salida todavía no llega a su fecha: no se puede marcar como realizada.'], 422);
        }

        DB::transaction(function () use ($salida, $nuevo, $reactivar) {
            $salida->update(['estado' => $nuevo]);

            // Al cancelar pudieron liberarse con el proveedor: se vuelven a reservar.
            if ($reactivar) {
                $salida->servicios()->update(['estado' => SalidaServicio::ESTADO_POR_RESERVAR]);
            }
        });
        $salida->load(self::RELACIONES);

        return response()->json([
            'code' => 200,
            'message' => $reactivar ? 'Salida reactivada: vuelve a reservar sus servicios con los proveedores.' : 'Estado de la salida actualizado.',
            'salida' => $this->resumenSalida($salida, true),
        ]);
    }

    // GET salidas-operativas/{id}/ordenes — fila 13, fase 2c (D14): la orden
    // de servicio de cada proveedor de la salida, con el texto para WhatsApp.
    public function ordenes(string $id, SalidaOperacionService $operacion)
    {
        $salida = SalidaOperativa::findOrFail($id);
        if (! in_array($salida->estado, SalidaOperativa::ESTADOS_EN_CURSO, true)) {
            return response()->json(['code' => 422, 'message' => 'Las órdenes de servicio son para salidas programadas o confirmadas.'], 422);
        }

        return response()->json(['code' => 200, 'ordenes' => $operacion->ordenes($salida)]);
    }

    // GET salidas-operativas/{id}/ordenes/{proveedorId}/pdf
    public function ordenPdf(string $id, string $proveedorId, SalidaOperacionService $operacion)
    {
        $salida = SalidaOperativa::with('tourOrigen')->findOrFail($id);
        $orden = collect($operacion->ordenes($salida))->firstWhere('proveedor_id', (int) $proveedorId);
        abort_if($orden === null, 404, 'Ese proveedor no tiene servicios ni vehículos en la salida.');

        $empresa = Company::first();
        $pdf = Pdf::loadView('pdf.agencia-viajes.orden-servicio', [
            'salida' => $salida,
            'orden' => $orden,
            'empresa' => $empresa,
            // NUNCA StorageUrl::resolve() dentro de un PDF (DomPDF no carga URLs remotas).
            'logoUrl' => StorageUrl::resolveParaPdf($empresa?->logo_horizontal),
            'generadoEn' => HoraPeru::ahora()->format('d/m/Y H:i'),
        ]);

        return $pdf->stream('orden_servicio_'.$salida->fecha->format('d-m-Y').'_'.Str::slug($orden['proveedor']).'.pdf');
    }

    // POST salidas-operativas/{id}/reprogramar — fila 13, fase 2c (D16, D17).
    // Mueve la salida completa a otra fecha (lluvia, derrumbe…). Por cada
    // reserva: reprogramar (por defecto) o cancelar (cancelar_reserva_ids).
    // - Reserva solo de ese día → ReservaController::reprogramar() (mueve la
    //   reserva completa).
    // - Viaje de varios días → solo se mueven sus servicios de esta salida
    //   (fecha manual), para no correr los otros días del viaje.
    // Lo configurado (proveedores, cantidades a mano, vehículos y guía) pasa a
    // la salida nueva, con los servicios en "Por reservar". Todo o nada: una
    // sola transacción.
    public function reprogramar(Request $request, string $id, SalidaOperacionService $operacion, SalidaEngancheService $enganche)
    {
        $salida = SalidaOperativa::with('reservaItems.reserva', 'reservaItems.alternativaItem')->findOrFail($id);

        if (! in_array($salida->estado, SalidaOperativa::ESTADOS_EN_CURSO, true)) {
            return response()->json(['code' => 422, 'message' => 'Solo se reprograma una salida programada o confirmada.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'fecha_nueva' => 'required|date_format:Y-m-d|after_or_equal:'.HoraPeru::hoyTexto(),
            'motivo' => 'required|string|max:500',
            'cancelar_reserva_ids' => 'nullable|array',
            'cancelar_reserva_ids.*' => 'integer',
            'motivo_cancelacion' => 'required_with:cancelar_reserva_ids|nullable|in:voluntaria,fuerza_mayor,clima,falta_pago_cuotas',
        ], [
            'fecha_nueva.after_or_equal' => 'La nueva fecha no puede ser anterior a hoy.',
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $fechaNueva = $request->get('fecha_nueva');
        if ($fechaNueva === $salida->fecha->toDateString()) {
            return response()->json(['code' => 422, 'message' => 'La salida ya es de esa fecha.'], 422);
        }

        $reservas = $salida->reservaItems->pluck('reserva')->filter()
            ->reject(fn ($r) => $r->estado === 'cancelada')->unique('id')->values();
        $aCancelar = collect($request->get('cancelar_reserva_ids', []))->map(fn ($r) => (int) $r);
        $ajenas = $aCancelar->diff($reservas->pluck('id'));
        if ($ajenas->isNotEmpty()) {
            return response()->json(['code' => 422, 'message' => 'Estas reservas no están en la salida: '.$ajenas->implode(', ')], 422);
        }

        $antes = $operacion->foto($salida->id);
        // Guía, hora y lugar de recojo que el operador ya tenía en la salida vieja.
        $datosSalida = $salida->only(['guia_id', 'hora', 'lugar_recojo']);
        $reservaController = app(ReservaController::class);
        $fechaVieja = $salida->fecha->toDateString();

        try {
            $resultado = DB::transaction(function () use (
                $salida, $reservas, $aCancelar, $fechaNueva, $fechaVieja, $request, $reservaController, $enganche, $antes, $datosSalida
            ) {
                $movidas = [];
                $noTocados = [];

                foreach ($reservas as $reserva) {
                    if ($aCancelar->contains($reserva->id)) {
                        $this->exigirOk($reservaController->cancelar(new Request(['motivo_cancelacion' => $request->get('motivo_cancelacion')]), (string) $reserva->id));

                        continue;
                    }

                    $soloEseDia = $reserva->fecha_viaje_desde?->toDateString() === $fechaVieja
                        && ($reserva->fecha_viaje_hasta === null || $reserva->fecha_viaje_hasta->toDateString() === $fechaVieja);

                    if ($soloEseDia) {
                        $respuesta = $this->exigirOk($reservaController->reprogramar(new Request([
                            'fecha_viaje_desde' => $fechaNueva,
                            'fecha_viaje_hasta' => $reserva->fecha_viaje_hasta ? $fechaNueva : null,
                            'motivo' => $request->get('motivo'),
                        ]), (string) $reserva->id));
                        $noTocados = array_merge($noTocados, $respuesta['items_no_tocados'] ?? []);
                    } else {
                        $noTocados = array_merge($noTocados, $this->moverServiciosDeLaSalida($reserva, $salida, $fechaNueva, $enganche));
                    }

                    $movidas[] = $reserva->id;
                }

                SalidaOperativa::eliminarSiQuedoVacia($salida->id);

                $nueva = $movidas ? SalidaOperativa::where([
                    'tour_origen_id' => $salida->tour_origen_id,
                    'fecha' => $fechaNueva,
                    'privada_reserva_id' => $salida->privada_reserva_id,
                ])->first() : null;

                if ($nueva) {
                    $this->llevarConfiguracion($antes, $nueva, $movidas, $datosSalida);
                }

                return ['nueva' => $nueva, 'movidas' => $movidas, 'no_tocados' => $noTocados];
            });
        } catch (HttpException $e) {
            return response()->json(['code' => $e->getStatusCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (AuthorizationException $e) {
            return response()->json(['code' => 403, 'message' => 'No tienes permiso sobre todas las reservas de la salida (alguna es de otro vendedor).'], 403);
        }

        $fechaTexto = \Carbon\Carbon::parse($fechaNueva)->format('d/m/Y');
        $avisos = [];
        foreach ($antes['servicios'] as $sv) {
            if ($sv['proveedor']) {
                $avisos[] = "{$sv['proveedor']} ({$sv['nombre']}): la salida del {$antes['fecha']} pasa al {$fechaTexto}. Hay que volver a reservar.";
            }
        }
        foreach ($antes['vehiculos'] as $v) {
            if ($v['proveedor']) {
                $avisos[] = "{$v['proveedor']} ({$v['nombre']}): la salida del {$antes['fecha']} pasa al {$fechaTexto}.";
            }
        }
        if ($aCancelar->isNotEmpty()) {
            $avisos[] = $aCancelar->count().' reserva(s) cancelada(s): revisa las cantidades con cada proveedor.';
        }
        // T-042: el día nuevo ya tenía una salida cancelada y las reservas cayeron en ella.
        if ($resultado['nueva']?->estado === SalidaOperativa::ESTADO_CANCELADA) {
            $avisos[] = $resultado['nueva']->avisoCancelada();
        }

        return response()->json([
            'code' => 200,
            'message' => count($resultado['movidas'])
                ? 'Salida reprogramada al '.$fechaTexto.'.'
                : 'No quedó ninguna reserva por mover: todas se cancelaron.',
            'salida_nueva_id' => $resultado['nueva']?->id,
            'reservas_reprogramadas' => $resultado['movidas'],
            'reservas_canceladas' => $aCancelar->values(),
            'items_no_tocados' => $resultado['no_tocados'],
            'avisos' => $avisos,
        ]);
    }

    // Respuesta de ReservaController que tiene que salir bien; si no, corta la
    // transacción con su mensaje.
    private function exigirOk($respuesta): array
    {
        $datos = $respuesta->getData(true);
        if ($respuesta->getStatusCode() !== 200) {
            throw new HttpException($respuesta->getStatusCode(), $datos['message'] ?? 'No se pudo completar la operación.');
        }

        return $datos;
    }

    // Viaje de varios días (D16): solo los servicios de esta salida cambian de
    // fecha; quedan como fecha manual para que un recálculo automático no los
    // devuelva. Lo ya facturado no se mueve (mismo criterio que reprogramar()).
    private function moverServiciosDeLaSalida($reserva, SalidaOperativa $salida, string $fechaNueva, SalidaEngancheService $enganche): array
    {
        $facturados = ReservaVenta::itemIdsFacturados($reserva->id);
        $noTocados = [];

        $items = ReservaItem::with('alternativaItem')
            ->where('reserva_id', $reserva->id)
            ->where('salida_operativa_id', $salida->id)
            ->get();

        foreach ($items as $item) {
            if ($facturados->contains($item->id)) {
                $noTocados[] = ['reserva_item_id' => $item->id, 'motivo' => 'ya_facturado'];

                continue;
            }

            $item->update([
                'fecha' => $fechaNueva,
                'fecha_origen' => ReservaItem::FECHA_ORIGEN_MANUAL,
                'salida_operativa_id' => null,
            ]);
            $enganche->enganchar($item, $item->alternativaItem, $fechaNueva);
        }

        return $noTocados;
    }

    // Pasa a la salida nueva lo que el operador ya había armado en la vieja
    // (D16): guía, proveedores y cantidades a mano de los servicios (quedan
    // "Por reservar": la fecha cambió) y los vehículos con sus reservas, si la
    // salida nueva todavía no tiene vehículos propios.
    private function llevarConfiguracion(array $antes, SalidaOperativa $nueva, array $movidas, array $datosSalida): void
    {
        // Guía: solo si la salida nueva no tenía. Hora y lugar de recojo: los
        // que el operador había dejado en la salida vieja (la nueva nació con
        // los del tour; si ya existía con otras reservas, se respetan los suyos).
        $cambios = [];
        if (! $nueva->guia_id && $datosSalida['guia_id']) {
            $cambios['guia_id'] = $datosSalida['guia_id'];
        }
        if ($nueva->reservaItems()->distinct()->count('reserva_id') === count($movidas)) {
            $cambios['hora'] = $datosSalida['hora'] ?? $nueva->hora;
            $cambios['lugar_recojo'] = $datosSalida['lugar_recojo'] ?? $nueva->lugar_recojo;
        }
        if ($cambios) {
            $nueva->update($cambios);
        }

        foreach ($antes['servicios'] as $sv) {
            $destino = $sv['paquete_plantilla_item_id']
                ? SalidaServicio::where('salida_operativa_id', $nueva->id)->where('paquete_plantilla_item_id', $sv['paquete_plantilla_item_id'])->first()
                : null;

            if ($destino) {
                $destino->update([
                    'proveedor_id' => $destino->proveedor_id ?? $sv['proveedor_id'],
                    'cantidad_manual' => $destino->cantidad_manual ?? $sv['cantidad_manual'],
                    'notas' => $destino->notas ?? $sv['notas'],
                ]);
            } elseif (! $sv['paquete_plantilla_item_id']) {
                SalidaServicio::create([
                    'salida_operativa_id' => $nueva->id,
                    'nombre' => $sv['nombre'],
                    'proveedor_id' => $sv['proveedor_id'],
                    'cantidad_manual' => $sv['cantidad_manual'],
                    'notas' => $sv['notas'],
                    'estado' => SalidaServicio::ESTADO_POR_RESERVAR,
                ]);
            }
        }

        if (SalidaVehiculo::where('salida_operativa_id', $nueva->id)->exists()) {
            return;
        }

        foreach ($antes['vehiculos'] as $v) {
            $vehiculo = SalidaVehiculo::create($v['datos'] + ['salida_operativa_id' => $nueva->id]);
            $vehiculo->reservas()->sync(array_values(array_intersect($v['reserva_ids'], $movidas)));
        }
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

        $facturados = ReservaVenta::itemIdsFacturados($reservaItem->reserva_id);

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
            'hora' => $s->hora ? substr((string) $s->hora, 0, 5) : null,
            'lugar_recojo' => $s->lugar_recojo,
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

        // Fila 13, fase 2b: servicios a reservar y vehículos (D10-D13).
        // Pasajeros que ocupan asiento por reserva: sin infantes.
        $ocupanPorReserva = $reservasUnicas->mapWithKeys(fn ($r) => [
            $r->id => $r->pasajeros->where('tipo_pax', '!=', 'infante')->count(),
        ]);
        $reservasEnVehiculo = $s->vehiculos->flatMap(fn ($v) => $v->reservas->pluck('id'))->unique();

        $base['servicios_total'] = $s->servicios->count();
        $base['servicios_sin_proveedor'] = $s->servicios->whereNull('proveedor_id')->count();
        $base['servicios_por_reservar'] = $s->servicios->where('estado', SalidaServicio::ESTADO_POR_RESERVAR)->count();
        $base['vehiculos_total'] = $s->vehiculos->count();
        $base['capacidad_total'] = $s->vehiculos->sum('capacidad');
        $base['reservas_sin_vehiculo'] = $s->vehiculos->isEmpty()
            ? 0
            : $reservasUnicas->pluck('id')->diff($reservasEnVehiculo)->count();

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

            $base['servicios'] = $s->servicios->map(fn (SalidaServicio $sv) => [
                'id' => $sv->id,
                'nombre' => $sv->nombre,
                'del_tour' => $sv->paquete_plantilla_item_id !== null,
                'proveedor_id' => $sv->proveedor_id,
                'proveedor' => $sv->proveedor ? ($sv->proveedor->nombre_comercial ?: $sv->proveedor->razon_social) : null,
                // Cantidad: la fijada a mano o, si no, adultos + niños de la salida.
                'cantidad' => $sv->cantidad_manual ?? $paxQueCuentan,
                'cantidad_manual' => $sv->cantidad_manual,
                'estado' => $sv->estado,
                'notas' => $sv->notas,
            ])->values();

            $base['vehiculos'] = $s->vehiculos->map(function (SalidaVehiculo $v) use ($ocupanPorReserva, $reservasUnicas) {
                $reservaIds = $v->reservas->pluck('id')->intersect($reservasUnicas->pluck('id'))->values();
                $pasajeros = $reservaIds->sum(fn ($id) => $ocupanPorReserva[$id] ?? 0);

                return [
                    'id' => $v->id,
                    'proveedor_id' => $v->proveedor_id,
                    'proveedor' => $v->proveedor ? ($v->proveedor->nombre_comercial ?: $v->proveedor->razon_social) : null,
                    'descripcion' => $v->descripcion,
                    'chofer_nombre' => $v->chofer_nombre,
                    'chofer_telefono' => $v->chofer_telefono,
                    'capacidad' => $v->capacidad,
                    'guia_id' => $v->guia_id,
                    'guia' => $v->guia?->nombre,
                    'notas' => $v->notas,
                    'reserva_ids' => $reservaIds,
                    'pasajeros' => $pasajeros,
                    'excede_capacidad' => $v->capacidad !== null && $pasajeros > $v->capacidad,
                ];
            })->values();
        }

        return $base;
    }
}
