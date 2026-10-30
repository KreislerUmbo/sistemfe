<?php

namespace App\Services\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaAnticipo;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use Illuminate\Support\Collection;

// "Modificar reserva" — calcular la diferencia entre lo vendido y la versión nueva (T-003;
// R-AGE-03, R-AGE-04, R-AGE-06, D-AGE-031, D-AGE-032). Solo lectura: no escribe nada en la
// reserva. El panel de la pantalla (T-007), el PDF (T-006) y aplicar (T-005, que la recalcula
// bajo lock) usan este mismo resultado.
//
// "Lo vendido" de un servicio es el precio de su ítem de origen (`item_origen_id`), que es lo
// que facturar lee en vivo. Todo el dinero se pasa a centavos enteros UNA vez, al leer cada
// ítem (centavos()); de ahí en adelante solo se suma y se resta, así la suma de las líneas
// cuadra al céntimo con los totales.
class ModificacionDiferenciaService
{
    public function calcular(ReservaModificacion $modificacion): DiferenciaModificacion
    {
        $reserva = Reserva::findOrFail($modificacion->reserva_id);
        $ventas = ReservaVenta::where('reserva_id', $reserva->id)->get();

        // Qué está facturado: siempre por los helpers de ReservaVenta. Una línea de tour con
        // cualquier parte facturada cuenta como facturada (R-AGE-04).
        $itemsFacturados = ReservaVenta::itemIdsDe($ventas)
            ->merge(ReservaVenta::itemsDeTourAMedias($ventas))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        $pasajerosFacturados = ReservaVenta::pasajeroIdsDe($ventas)->map(fn ($id) => (int) $id)->all();

        $alternativaNueva = $modificacion->alternativaNueva()->firstOrFail();
        // Un servicio de ajuste (T-005) no se compara: su precio ya está dentro del ítem que ajusta.
        $itemsNuevos = ReservaController::filtrarItemsParaReserva(
            AlternativaItem::where('alternativa_id', $alternativaNueva->id)->whereNull('ajuste_de_item_id')->orderBy('id')->get()
        );

        $lineas = $this->lineasDeServicios($reserva, $itemsNuevos, $ventas, $itemsFacturados);
        $pasajeros = $this->pasajeros($reserva, $modificacion, $ventas, $pasajerosFacturados, $itemsNuevos);
        $bloqueos = $this->bloqueos($lineas, $pasajeros);
        $totales = $this->totales($reserva, $lineas);

        $hayCambios = $lineas->contains(fn (array $l) => $l['estado'] !== 'igual')
            || $pasajeros->contains(fn (array $p) => $p['estado'] !== 'igual');

        $lineas = $lineas->values()->all();
        $pasajeros = $pasajeros->values()->all();

        return new DiferenciaModificacion(
            moneda: (string) $alternativaNueva->moneda_cotizacion,
            lineas: $lineas,
            pasajeros: $pasajeros,
            totales: $totales,
            bloqueos: $bloqueos,
            hayCambios: $hayCambios,
            huella: $this->huella($lineas, $pasajeros, $totales, $bloqueos),
        );
    }

    // ── Servicios ──────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, AlternativaItem>  $itemsNuevos
     * @return Collection<int, array<string, mixed>>
     */
    private function lineasDeServicios(Reserva $reserva, Collection $itemsNuevos, Collection $ventas, array $itemsFacturados): Collection
    {
        // Los servicios de la reserva que son un "Ajuste — <servicio>" (T-005) no se comparan ni
        // se quitan: cobran la diferencia que ya está en el precio del ítem que ajustan. Contarlos
        // aparte sumaría esa diferencia dos veces en una modificación encadenada.
        $reservaItems = ReservaItem::where('reserva_id', $reserva->id)->with('alternativaItem')->orderBy('id')->get()
            ->reject(fn (ReservaItem $ri) => $ri->alternativaItem?->ajuste_de_item_id !== null)->values();
        $reservaItemPorAlternativaItem = $reservaItems->keyBy('alternativa_item_id');
        $origenes = AlternativaItem::whereIn('id', $itemsNuevos->pluck('item_origen_id')->filter())->get()->keyBy('id');
        $pasajerosPorCotizacion = [];

        $lineas = collect();
        $reservaItemsConSucesor = [];

        foreach ($itemsNuevos as $item) {
            $origen = $item->item_origen_id ? $origenes->get($item->item_origen_id) : null;
            $reservaItem = $origen ? $this->reservaItemDe($origen, $reservaItemPorAlternativaItem) : null;

            // Nota de la revisión de T-002: un servicio exclusivo de pasajeros que ya no están
            // llega con pax_incluidos = [], que en el sistema significa "para todos". No se
            // cobra a todos: la línea queda para revisar y bloquea hasta que el vendedor la
            // asigne o la quite (confirmarla a mano no sirve, ver $linea['sin_pasajeros']).
            $sinPasajeros = $origen
                && is_array($item->pax_incluidos) && $item->pax_incluidos === []
                && is_array($origen->pax_incluidos) && $origen->pax_incluidos !== [];

            // Sin origen (o sin servicio en la reserva que lo respalde) no hay con qué comparar:
            // es un servicio nuevo. Salvo que haya quedado sin pasajeros: sincronizar-items no
            // crea en la reserva un servicio solo de pasajeros quitados, y su copia no puede
            // salir como "agregado para todos".
            if (! $origen || ! $reservaItem) {
                $lineas->push($sinPasajeros
                    ? $this->linea($item, 'para_revisar', 0, $this->centavos($item), null, false, [], ['sin_pasajeros' => true])
                    : $this->linea($item, 'agregado', 0, $this->centavos($item), null, false, []));

                continue;
            }

            $reservaItemsConSucesor[$reservaItem->id] = true;

            $vendido = $this->centavos($origen);
            $nuevo = $this->centavos($item);
            $facturado = in_array($reservaItem->id, $itemsFacturados, true);
            $saleIds = $facturado ? $this->saleIdsDeItem($ventas, $reservaItem->id) : [];

            $paxVendidos = $this->pasajerosQueLoUsan($origen, $pasajerosPorCotizacion);
            $paxNuevos = $this->pasajerosQueLoUsan($item, $pasajerosPorCotizacion);

            if ($sinPasajeros) {
                $estado = 'para_revisar';
            } elseif ($nuevo !== $vendido) {
                $estado = match (true) {
                    ! $facturado => 'cambio_precio',
                    $nuevo > $vendido => 'ajuste',
                    default => 'requiere_nota_credito',
                };
            } elseif ($item->modo_precio === 'tarifa_fija' && $paxNuevos !== $paxVendidos) {
                $estado = 'para_revisar';
            } else {
                $estado = 'igual';
            }

            $lineas->push($this->linea($item, $estado, $vendido, $nuevo, $reservaItem, $facturado, $saleIds, [
                'sin_pasajeros' => $sinPasajeros,
                'pasajeros_vendidos' => $item->modo_precio === 'tarifa_fija' ? $paxVendidos : null,
                'pasajeros_nuevos' => $item->modo_precio === 'tarifa_fija' ? $paxNuevos : null,
                'revisado' => $estado === 'para_revisar' && ! $sinPasajeros && $item->revisado_en_modificacion !== null,
            ]));
        }

        // Cada servicio de la reserva sin sucesor en la versión nueva se quita.
        foreach ($reservaItems as $reservaItem) {
            if (isset($reservaItemsConSucesor[$reservaItem->id])) {
                continue;
            }

            $itemVendido = $reservaItem->alternativaItem;
            $facturado = in_array($reservaItem->id, $itemsFacturados, true);

            $lineas->push([
                'clave' => 'reserva_item:'.$reservaItem->id,
                'estado' => $facturado ? 'requiere_nota_credito' : 'quitado',
                'descripcion' => $itemVendido ? ReservaController::resolverNombreItem($itemVendido, $reservaItem) : 'Servicio '.$reservaItem->id,
                'alternativa_item_id' => null,
                'item_origen_id' => $itemVendido?->id,
                'reserva_item_id' => $reservaItem->id,
                'modo_precio' => $itemVendido?->modo_precio,
                'vendido_centavos' => $itemVendido ? $this->centavos($itemVendido) : 0,
                'nuevo_centavos' => 0,
                'diferencia_centavos' => $itemVendido ? -$this->centavos($itemVendido) : 0,
                'facturado' => $facturado,
                'sale_ids' => $facturado ? $this->saleIdsDeItem($ventas, $reservaItem->id) : [],
                'tip_afe_igv' => $reservaItem->tip_afe_igv ?? $itemVendido?->tip_afe_igv,
                'destino_tributario' => $reservaItem->destino_tributario ?? $itemVendido?->destino_tributario,
                'sin_pasajeros' => false,
                'pasajeros_vendidos' => null,
                'pasajeros_nuevos' => null,
                'revisado' => false,
            ]);
        }

        return $lineas;
    }

    // El servicio de la reserva que hoy corresponde a un ítem de origen. Si la reserva ya pasó
    // por una modificación aplicada, un servicio facturado sigue apuntando a la versión
    // anterior (D-AGE-031): se sube por la cadena de item_origen_id hasta encontrarlo.
    private function reservaItemDe(AlternativaItem $origen, Collection $reservaItemPorAlternativaItem): ?ReservaItem
    {
        $id = $origen->id;

        for ($saltos = 0; $id && $saltos < 10; $saltos++) {
            if ($reservaItem = $reservaItemPorAlternativaItem->get($id)) {
                return $reservaItem;
            }
            $id = AlternativaItem::whereKey($id)->value('item_origen_id');
        }

        return null;
    }

    // Cuántos pasajeros usan el servicio: los asignados, o todos los de su cotización si
    // pax_incluidos es null.
    private function pasajerosQueLoUsan(AlternativaItem $item, array &$cache): int
    {
        if (is_array($item->pax_incluidos)) {
            return count($item->pax_incluidos);
        }

        $cotizacionId = (int) $item->alternativa()->value('cotizacion_id');

        return $cache[$cotizacionId] ??= CotizacionPasajero::where('cotizacion_id', $cotizacionId)->count();
    }

    /**
     * @param  array<int, int>  $saleIds
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function linea(AlternativaItem $item, string $estado, int $vendido, int $nuevo, ?ReservaItem $reservaItem, bool $facturado, array $saleIds, array $extra = []): array
    {
        return $extra + [
            'sin_pasajeros' => false,
            'pasajeros_vendidos' => null,
            'pasajeros_nuevos' => null,
            'revisado' => false,
        ] + [
            'clave' => 'item:'.$item->id,
            'estado' => $estado,
            'descripcion' => ReservaController::resolverNombreItem($item, $reservaItem),
            'alternativa_item_id' => $item->id,
            'item_origen_id' => $item->item_origen_id,
            'reserva_item_id' => $reservaItem?->id,
            'modo_precio' => $item->modo_precio,
            'vendido_centavos' => $vendido,
            'nuevo_centavos' => $nuevo,
            'diferencia_centavos' => $nuevo - $vendido,
            'facturado' => $facturado,
            'sale_ids' => $saleIds,
            // Una línea `ajuste` lleva el tratamiento tributario del servicio que ajusta.
            'tip_afe_igv' => $item->tip_afe_igv,
            'destino_tributario' => $item->destino_tributario,
        ];
    }

    // Único punto de conversión a centavos: precio unitario (post-descuento, en la moneda de la
    // alternativa) por la cantidad cuando el servicio es de tarifa fija.
    private function centavos(AlternativaItem $item): int
    {
        $unitario = (int) round(((float) ($item->precio_convertido ?? $item->precio_venta_snapshot)) * 100);

        return $item->modo_precio === 'tarifa_fija' ? $unitario * (int) $item->cantidad : $unitario;
    }

    /** @return array<int, int> */
    private function saleIdsDeItem(Collection $ventas, int $reservaItemId): array
    {
        return $ventas
            ->filter(fn (ReservaVenta $rv) => in_array($reservaItemId, array_map('intval', $rv->reserva_item_ids ?? []), true)
                || collect($rv->partes_tour ?? [])->contains(fn (array $p) => (int) ($p['reserva_item_id'] ?? 0) === $reservaItemId))
            ->pluck('sale_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    // ── Pasajeros ──────────────────────────────────────────────────────────────

    /** @return Collection<int, array<string, mixed>> */
    private function pasajeros(Reserva $reserva, ReservaModificacion $modificacion, Collection $ventas, array $pasajerosFacturados, Collection $itemsNuevos): Collection
    {
        // Penalidades de la versión nueva (T-004), por el pasajero de la reserva al que
        // corresponden. Solo una por pasajero (lo asegura PenalidadPasajeroService).
        $penalidades = $itemsNuevos->whereNotNull('penalidad_reserva_pasajero_id')
            ->keyBy(fn (AlternativaItem $i) => (int) $i->penalidad_reserva_pasajero_id);

        $copias = CotizacionPasajero::where('cotizacion_id', $modificacion->cotizacion_id)->orderBy('id')->get();
        $deLaReserva = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get();
        $conCopia = $copias->pluck('reserva_pasajero_id')->filter()->map(fn ($id) => (int) $id)->all();

        $filas = collect();

        foreach ($copias as $copia) {
            $filas->push([
                'clave' => 'copia:'.$copia->id,
                'estado' => $copia->reserva_pasajero_id ? 'igual' : 'agregado',
                'reserva_pasajero_id' => $copia->reserva_pasajero_id,
                'cotizacion_pasajero_id' => $copia->id,
                'nombre' => $copia->reserva_pasajero_id ? $deLaReserva->firstWhere('id', $copia->reserva_pasajero_id)?->nombre : null,
                'tipo_pax' => $copia->tipo_pax,
                'facturado' => false,
                'sale_ids' => [],
                'falta_penalidad' => false,
                // El pasajero sigue en la modificación: su penalidad ya no vale (R-AGE-05).
                'penalidad_alternativa_item_id' => null,
                'penalidad_sobrante_item_id' => $copia->reserva_pasajero_id
                    ? $penalidades->get((int) $copia->reserva_pasajero_id)?->id
                    : null,
            ]);
        }

        foreach ($deLaReserva as $pasajero) {
            if (in_array($pasajero->id, $conCopia, true)) {
                continue;
            }

            $facturado = in_array($pasajero->id, $pasajerosFacturados, true);
            $penalidad = $penalidades->get($pasajero->id);
            $filas->push([
                'clave' => 'pasajero:'.$pasajero->id,
                'estado' => $facturado ? 'requiere_nota_credito' : 'quitado',
                'reserva_pasajero_id' => $pasajero->id,
                'cotizacion_pasajero_id' => null,
                'nombre' => $pasajero->nombre,
                'tipo_pax' => $pasajero->tipo_pax,
                'facturado' => $facturado,
                'sale_ids' => $facturado
                    ? $ventas->filter(fn (ReservaVenta $rv) => in_array($pasajero->id, array_map('intval', $rv->reserva_pasajero_ids ?? []), true))
                        ->pluck('sale_id')->map(fn ($id) => (int) $id)->unique()->values()->all()
                    : [],
                // Un pasajero no facturado que se quita necesita su penalidad (R-AGE-05).
                'falta_penalidad' => ! $facturado && ! $penalidad,
                'penalidad_alternativa_item_id' => ! $facturado ? $penalidad?->id : null,
                // Un facturado no lleva penalidad: primero va la nota de crédito.
                'penalidad_sobrante_item_id' => $facturado ? $penalidad?->id : null,
            ]);
        }

        return $filas;
    }

    // ── Bloqueos (R-AGE-06) ────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function bloqueos(Collection $lineas, Collection $pasajeros): array
    {
        $bloqueos = [];

        foreach ($lineas as $linea) {
            if ($linea['estado'] === 'para_revisar' && $linea['sin_pasajeros']) {
                $bloqueos[] = [
                    'tipo' => 'servicio_sin_pasajeros',
                    'mensaje' => "«{$linea['descripcion']}» era solo de pasajeros que ya no están: asígnalo a alguien o quítalo.",
                    'alternativa_item_id' => $linea['alternativa_item_id'],
                    'sale_id' => null,
                    'reserva_pasajero_id' => null,
                ];
            } elseif ($linea['estado'] === 'para_revisar' && ! $linea['revisado']) {
                $bloqueos[] = [
                    'tipo' => 'para_revisar',
                    'mensaje' => "«{$linea['descripcion']}» cambió de pasajeros y su precio no se recalcula solo: revísalo y confírmalo.",
                    'alternativa_item_id' => $linea['alternativa_item_id'],
                    'sale_id' => null,
                    'reserva_pasajero_id' => null,
                ];
            } elseif ($linea['estado'] === 'requiere_nota_credito') {
                foreach ($linea['sale_ids'] ?: [null] as $saleId) {
                    $bloqueos[] = [
                        'tipo' => 'requiere_nota_credito',
                        'mensaje' => "«{$linea['descripcion']}» ya está facturado: emite la nota de crédito antes de aplicar.",
                        'alternativa_item_id' => $linea['alternativa_item_id'],
                        'sale_id' => $saleId,
                        'reserva_pasajero_id' => null,
                    ];
                }
            }
        }

        foreach ($pasajeros as $pasajero) {
            if ($pasajero['penalidad_sobrante_item_id']) {
                $bloqueos[] = [
                    'tipo' => 'penalidad_sobrante',
                    'mensaje' => 'La penalidad del pasajero '.($pasajero['nombre'] ?: '#'.$pasajero['reserva_pasajero_id']).' ya no corresponde: bórrala.',
                    'alternativa_item_id' => $pasajero['penalidad_sobrante_item_id'],
                    'sale_id' => null,
                    'reserva_pasajero_id' => $pasajero['reserva_pasajero_id'],
                ];
            }

            if ($pasajero['estado'] === 'requiere_nota_credito') {
                foreach ($pasajero['sale_ids'] ?: [null] as $saleId) {
                    $bloqueos[] = [
                        'tipo' => 'requiere_nota_credito',
                        'mensaje' => 'El pasajero '.($pasajero['nombre'] ?: '#'.$pasajero['reserva_pasajero_id']).' ya está facturado: emite la nota de crédito antes de quitarlo.',
                        'alternativa_item_id' => null,
                        'sale_id' => $saleId,
                        'reserva_pasajero_id' => $pasajero['reserva_pasajero_id'],
                    ];
                }
            } elseif ($pasajero['falta_penalidad']) {
                $bloqueos[] = [
                    'tipo' => 'falta_penalidad',
                    'mensaje' => 'Falta la penalidad del pasajero '.($pasajero['nombre'] ?: '#'.$pasajero['reserva_pasajero_id']).'.',
                    'alternativa_item_id' => null,
                    'sale_id' => null,
                    'reserva_pasajero_id' => $pasajero['reserva_pasajero_id'],
                ];
            }
        }

        return $bloqueos;
    }

    // ── Totales ────────────────────────────────────────────────────────────────

    /** @return array<string, int> */
    private function totales(Reserva $reserva, Collection $lineas): array
    {
        $vendido = (int) $lineas->sum('vendido_centavos');
        $nuevo = (int) $lineas->sum('nuevo_centavos');

        return [
            'vendido' => $vendido,
            'nuevo' => $nuevo,
            'diferencia' => $nuevo - $vendido,
            // Lo vendido de los servicios que ya están en alguna factura (misma base que "vendido").
            'ya_facturado' => (int) $lineas->where('facturado', true)->sum('vendido_centavos'),
            'anticipos_aplicados' => (int) ReservaAnticipo::where('reserva_id', $reserva->id)->get()
                ->sum(fn (ReservaAnticipo $a) => (int) round((float) $a->monto_asignado * 100)),
        ];
    }

    // Hash estable: mismas filas en el mismo orden, sin fechas ni valores volátiles. Incluye si
    // un "para revisar" está confirmado, porque eso cambia los bloqueos.
    private function huella(array $lineas, array $pasajeros, array $totales, array $bloqueos): string
    {
        $ordenar = fn (array $filas) => collect($filas)->sortBy('clave')->values()->all();

        return hash('sha256', json_encode([
            'lineas' => $ordenar($lineas),
            'pasajeros' => $ordenar($pasajeros),
            'totales' => $totales,
            'bloqueos' => $bloqueos,
        ], JSON_THROW_ON_ERROR));
    }
}
