<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajeAereo;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;
use Illuminate\Support\Collection;

// Copia profunda de una alternativa (T-002): lo que antes vivía dentro de
// AlternativaController::duplicar(), extraído para que lo usen también
// "Modificar reserva" (ModificacionReservaService) y duplicar() sin que el
// comportamiento de ninguno cambie. Los comentarios con fecha son los del
// código original (auditoría de mantenibilidad del 05-sep-2026): explican por
// qué cada campo se clona como se clona.
//
// No abre transacción: el que llama la abre (copiar() escribe en ~10 tablas y
// tiene que ser todo o nada).
class AlternativaCopiaService
{
    /**
     * @param  Collection<int, AlternativaItem>|null  $items  ítems a copiar; null = todos los de la original
     * @return array{0: Alternativa, 1: array<int, AlternativaItem>}  la copia y el mapa id del ítem original => ítem copiado
     */
    public function copiar(Alternativa $original, int $cotizacionDestinoId, string $nombre, ?Collection $items = null): array
    {
        $items ??= $original->items()->get();

        $nueva = Alternativa::create([
            'cotizacion_id' => $cotizacionDestinoId,
            'nombre' => $nombre,
            'estado' => 'borrador',
            'moneda_cotizacion' => $original->moneda_cotizacion,
            'tipo_cambio_aplicado' => $original->tipo_cambio_aplicado,
            'tipo_cambio_origen' => $original->tipo_cambio_origen,
            'descuento_global_pct' => $original->descuento_global_pct,
            'total' => $original->total,
        ]);

        // Sesión 12c — clonar alternativa_destinos ANTES que los ítems
        // (que pueden referenciarlos): cada destino de la copia es una
        // fila propia, nunca comparte id con el original (misma razón
        // que las opciones de mayorista se clonan abajo en vez de
        // remapear al original).
        $destinosClonados = [];
        foreach ($original->destinos as $destino) {
            $nuevoDestino = AlternativaDestino::create([
                'alternativa_id' => $nueva->id,
                'destino_atractivo_id' => $destino->destino_atractivo_id,
                'destino_texto' => $destino->destino_texto,
                'orden' => $destino->orden,
                'fecha_inicio' => $destino->fecha_inicio,
                'fecha_fin' => $destino->fecha_fin,
            ]);
            $destinosClonados[$destino->id] = $nuevoDestino->id;
        }

        // Bug real (07-sep-2026, auditoría de mantenibilidad 05-sep-2026):
        // OpcionHotel "ad-hoc" (Local/Nacional, "Hotel sin catálogo" —
        // nace SIN opcion_mayorista_id, ver agregarGrupoHotelAdhocLocal()
        // en editar.vue) nunca se clonaba en absoluto — la copia de una
        // alternativa con un hotel así cargado dejaba sus ítems sin
        // opcion_hotel_tarifa_id (o, peor, compartiendo la fila del
        // ORIGINAL entre las 2 alternativas). $tarifasHotelClonadas es
        // UN SOLO mapa para los dos orígenes posibles de hotel — acá se
        // arranca con el ad-hoc (tiene que estar listo ANTES de crear
        // los ítems, igual que $destinosClonados arriba); el de
        // mayorista se agrega más abajo, después de clonar
        // OpcionMayorista, y el remap final de los ítems corre una sola
        // vez al final para ambos casos.
        $tarifasHotelClonadas = [];
        $idsTarifaHotelAdhoc = $items
            ->whereNotNull('opcion_hotel_tarifa_id')
            ->whereNull('opcion_mayorista_id')
            ->pluck('opcion_hotel_tarifa_id')
            ->unique();
        $idsHotelAdhoc = OpcionHotelTarifa::whereIn('id', $idsTarifaHotelAdhoc)->pluck('opcion_hotel_id')->unique();
        foreach (OpcionHotel::whereIn('id', $idsHotelAdhoc)->whereNull('opcion_mayorista_id')->get() as $hotelAdhoc) {
            $nuevoHotelAdhoc = OpcionHotel::create([
                'proveedor_id' => $hotelAdhoc->proveedor_id,
                'proveedor_promovido_id' => $hotelAdhoc->proveedor_promovido_id,
                'nombre_hotel' => $hotelAdhoc->nombre_hotel,
                'categoria_estrellas' => $hotelAdhoc->categoria_estrellas,
                'moneda' => $hotelAdhoc->moneda,
                'edad_max_infante_gratis' => $hotelAdhoc->edad_max_infante_gratis,
                'edad_max_nino_cama_adicional' => $hotelAdhoc->edad_max_nino_cama_adicional,
                'fotos' => $hotelAdhoc->fotos,
            ]);

            foreach (OpcionHotelTarifa::where('opcion_hotel_id', $hotelAdhoc->id)->get() as $tarifa) {
                $nuevaTarifa = OpcionHotelTarifa::create([
                    'opcion_hotel_id' => $nuevoHotelAdhoc->id,
                    'tipo_habitacion' => $tarifa->tipo_habitacion,
                    'precio_costo' => $tarifa->precio_costo,
                    'precio_venta' => $tarifa->precio_venta,
                    'proveedor_tarifa_id' => $tarifa->proveedor_tarifa_id,
                    'precio_costo_cama_adicional' => $tarifa->precio_costo_cama_adicional,
                    'precio_venta_cama_adicional' => $tarifa->precio_venta_cama_adicional,
                    'tip_afe_igv' => $tarifa->tip_afe_igv,
                    'destino_tributario' => $tarifa->destino_tributario,
                ]);
                $tarifasHotelClonadas[$tarifa->id] = $nuevaTarifa->id;
            }
        }

        // Ítems (opcion_mayorista_id y opcion_hotel_tarifa_id de hotel de
        // MAYORISTA todavía no — se remapean al final, una vez clonado
        // el árbol de opciones más abajo; el de hotel AD-HOC ya se
        // resuelve acá porque su mapa ya está completo).
        //
        // Bug real (07-sep-2026, auditoría de mantenibilidad
        // 05-sep-2026): grupo_opcion_id/opcion_elegida/guia_tarifa_id/
        // tip_afe_igv/destino_tributario nunca se copiaban — una
        // alternativa duplicada con un comparador de hoteles armado
        // perdía el agrupamiento COMPLETO (los hoteles comparados
        // quedaban como ítems sueltos sin relación entre sí), un ítem
        // de guía quedaba sin guía real asociada, y el tratamiento
        // tributario resuelto (incluida la exoneración Amazonía) se
        // perdía en silencio cayendo al default del tenant.
        $itemsClonados = [];
        $cargasClonadas = [];
        foreach ($items as $item) {
            $nuevoItem = AlternativaItem::create([
                'alternativa_id' => $nueva->id,
                'alternativa_destino_id' => $item->alternativa_destino_id !== null
                    ? ($destinosClonados[$item->alternativa_destino_id] ?? null)
                    : null,
                'origen_tipo' => $item->origen_tipo,
                'proveedor_tarifa_id' => $item->proveedor_tarifa_id,
                'opcion_hotel_tarifa_id' => $item->opcion_hotel_tarifa_id !== null
                    ? ($tarifasHotelClonadas[$item->opcion_hotel_tarifa_id] ?? null)
                    : null,
                // T-024: la copia lleva su propio grupo (mismo agrupamiento, otro
                // identificador) — compartido, "eliminar todo este bloque" en una
                // alternativa borraba también el de la otra.
                'grupo_opcion_id' => $item->grupo_opcion_id !== null ? ($cargasClonadas[$item->grupo_opcion_id] ??= (string) \Illuminate\Support\Str::uuid()) : null,
                'opcion_elegida' => $item->opcion_elegida,
                'guia_tarifa_id' => $item->guia_tarifa_id,
                'tour_origen_id' => $item->tour_origen_id,
                'dia_referencial' => $item->dia_referencial,
                'descripcion_manual' => $item->descripcion_manual,
                'proveedor_sugerido_manual' => $item->proveedor_sugerido_manual,
                'proveedor_promovido_id' => $item->proveedor_promovido_id,
                'modo_precio' => $item->modo_precio,
                'cantidad' => $item->cantidad,
                'pax_incluidos' => $item->pax_incluidos,
                'moneda_costo' => $item->moneda_costo,
                'costo_snapshot' => $item->costo_snapshot,
                'precio_venta_snapshot' => $item->precio_venta_snapshot,
                'descuento_pct' => $item->descuento_pct,
                'precio_convertido' => $item->precio_convertido,
                'tip_afe_igv' => $item->tip_afe_igv,
                'destino_tributario' => $item->destino_tributario,
                // Fila 13, fase 1b — línea de tour: la copia lleva cargas
                // propias (mismo agrupamiento, otro identificador), para
                // que mover o quitar en una alternativa no toque la otra.
                'carga_tour' => $item->carga_tour !== null ? ($cargasClonadas[$item->carga_tour] ??= (string) \Illuminate\Support\Str::uuid()) : null,
                'carga_combo' => $item->carga_combo !== null ? ($cargasClonadas[$item->carga_combo] ??= (string) \Illuminate\Support\Str::uuid()) : null,
                'combo_origen_id' => $item->combo_origen_id,
                'tour_linea' => $item->tour_linea,
                'temporada_id' => $item->temporada_id,
                'desglose_costo_snapshot' => $item->desglose_costo_snapshot,
                'precio_manual' => $item->precio_manual,
            ]);
            $itemsClonados[$item->id] = $nuevoItem;

            if ($item->origen_tipo === AlternativaItem::ORIGEN_PASAJE_AEREO) {
                $pasaje = CotizacionPasajeAereo::where('alternativa_item_id', $item->id)->first();
                if ($pasaje) {
                    CotizacionPasajeAereo::create([
                        'alternativa_item_id' => $nuevoItem->id,
                        'aerolinea' => $pasaje->aerolinea,
                        'itinerario' => $pasaje->itinerario,
                        'moneda' => $pasaje->moneda,
                        'tarifa_base_adulto' => $pasaje->tarifa_base_adulto,
                        'tarifa_base_nino' => $pasaje->tarifa_base_nino,
                        'tarifa_base_infante' => $pasaje->tarifa_base_infante,
                        'cargos' => $pasaje->cargos,
                        'tua_incluida_en_tarifa' => $pasaje->tua_incluida_en_tarifa,
                        'fee_agencia_monto' => $pasaje->fee_agencia_monto,
                        'montos_por_pasajero' => $pasaje->montos_por_pasajero,
                        'tip_afe_igv' => $pasaje->tip_afe_igv,
                        'fecha_cotizado' => $pasaje->fecha_cotizado,
                        'tarifa_valida_hasta' => $pasaje->tarifa_valida_hasta,
                        'costo_total' => $pasaje->costo_total,
                        'precio_venta_total' => $pasaje->precio_venta_total,
                    ]);
                }
            }
        }

        $opcionesClonadas = [];
        $opcionalesClonados = [];
        foreach (OpcionMayorista::where('alternativa_id', $original->id)->get() as $opcion) {
            $nuevaOpcion = OpcionMayorista::create([
                'alternativa_id' => $nueva->id,
                'alternativa_destino_id' => $opcion->alternativa_destino_id !== null
                    ? ($destinosClonados[$opcion->alternativa_destino_id] ?? null)
                    : null,
                'proveedor_id' => $opcion->proveedor_id,
                'salida_mayorista_id' => $opcion->salida_mayorista_id,
                'moneda' => $opcion->moneda,
                'incluye' => $opcion->incluye,
                'notas' => $opcion->notas,
                'vuelo_aerolinea' => $opcion->vuelo_aerolinea,
                'vuelo_detalle' => $opcion->vuelo_detalle,
                'estado' => $opcion->estado,
                // Sesión 12e — el vínculo a contenido_tour (y su
                // snapshot ya congelado) se lleva tal cual a la copia,
                // sin remapear: ContenidoTour es un catálogo
                // compartido, no pertenece a ninguna alternativa.
                'contenido_tour_id' => $opcion->contenido_tour_id,
                'contenido_tour_descripcion_snapshot' => $opcion->contenido_tour_descripcion_snapshot,
                'contenido_tour_fotos_snapshot' => $opcion->contenido_tour_fotos_snapshot,
            ]);
            $opcionesClonadas[$opcion->id] = $nuevaOpcion->id;

            foreach (OpcionMayoristaOpcional::where('opcion_mayorista_id', $opcion->id)->get() as $opcional) {
                $nuevoOpcional = OpcionMayoristaOpcional::create([
                    'opcion_mayorista_id' => $nuevaOpcion->id,
                    'nombre' => $opcional->nombre,
                    'precio_por_persona' => $opcional->precio_por_persona,
                    'moneda' => $opcional->moneda,
                    'incluye' => $opcional->incluye,
                    'no_incluye' => $opcional->no_incluye,
                    'contenido_tour_id' => $opcional->contenido_tour_id,
                    'contenido_tour_descripcion_snapshot' => $opcional->contenido_tour_descripcion_snapshot,
                    'contenido_tour_fotos_snapshot' => $opcional->contenido_tour_fotos_snapshot,
                ]);
                // 07-sep-2026 — mismo criterio que $tarifasHotelClonadas:
                // un ítem real agregado desde este opcional (ver
                // AlternativaItemController::crearItemMayorista()) debe
                // remapear a la copia, no seguir apuntando al opcional
                // del original.
                $opcionalesClonados[$opcional->id] = $nuevoOpcional->id;
            }

            // paquete_plantilla_id se quitó de la tabla (migración
            // 2026_08_11_090500_drop_paquete_plantilla_id_from_opciones_hotel_table.php,
            // ver docblock del modelo) — ya no está en $fillable, así
            // que mandarlo acá no hacía nada (Eloquent lo descarta en
            // silencio). Resto de campos completados (07-sep-2026,
            // mismo hallazgo que arriba): faltaban moneda/edades/fotos
            // del hotel, y proveedor_tarifa_id/camas
            // adicionales/tratamiento tributario de cada tarifa — una
            // alternativa duplicada perdía las fotos del hotel y
            // desvinculaba sus tarifas de la tarifa real del proveedor
            // (getPrecioVentaAttribute() deja de seguir el precio en
            // vivo sin proveedor_tarifa_id).
            foreach (OpcionHotel::where('opcion_mayorista_id', $opcion->id)->get() as $hotel) {
                $nuevoHotel = OpcionHotel::create([
                    'opcion_mayorista_id' => $nuevaOpcion->id,
                    'proveedor_id' => $hotel->proveedor_id,
                    'proveedor_promovido_id' => $hotel->proveedor_promovido_id,
                    'nombre_hotel' => $hotel->nombre_hotel,
                    'categoria_estrellas' => $hotel->categoria_estrellas,
                    'moneda' => $hotel->moneda,
                    'edad_max_infante_gratis' => $hotel->edad_max_infante_gratis,
                    'edad_max_nino_cama_adicional' => $hotel->edad_max_nino_cama_adicional,
                    'fotos' => $hotel->fotos,
                ]);

                foreach (OpcionHotelTarifa::where('opcion_hotel_id', $hotel->id)->get() as $tarifa) {
                    $nuevaTarifa = OpcionHotelTarifa::create([
                        'opcion_hotel_id' => $nuevoHotel->id,
                        'tipo_habitacion' => $tarifa->tipo_habitacion,
                        'precio_costo' => $tarifa->precio_costo,
                        'precio_venta' => $tarifa->precio_venta,
                        'proveedor_tarifa_id' => $tarifa->proveedor_tarifa_id,
                        'precio_costo_cama_adicional' => $tarifa->precio_costo_cama_adicional,
                        'precio_venta_cama_adicional' => $tarifa->precio_venta_cama_adicional,
                        'tip_afe_igv' => $tarifa->tip_afe_igv,
                        'destino_tributario' => $tarifa->destino_tributario,
                    ]);
                    $tarifasHotelClonadas[$tarifa->id] = $nuevaTarifa->id;
                }
            }
        }

        // Remap final: opcion_mayorista_id (ya existía) +
        // opcion_hotel_tarifa_id de un hotel de MAYORISTA (07-sep-2026 —
        // recién quedó armado el mapa arriba, después de los ítems). El
        // de hotel AD-HOC ya se resolvió al crear el ítem más arriba,
        // este remap no lo vuelve a tocar (el item original de un hotel
        // ad-hoc nunca tiene opcion_mayorista_id, así que solo entra acá
        // por el segundo `if`, y como su id YA está en el mapa desde
        // antes de crear los ítems, el valor que resulta es el mismo —
        // no hay riesgo de pisarlo con otra cosa).
        foreach ($items as $itemOriginal) {
            if (! isset($itemsClonados[$itemOriginal->id])) {
                continue;
            }

            $cambios = [];
            if ($itemOriginal->opcion_mayorista_id !== null && isset($opcionesClonadas[$itemOriginal->opcion_mayorista_id])) {
                $cambios['opcion_mayorista_id'] = $opcionesClonadas[$itemOriginal->opcion_mayorista_id];
            }
            if ($itemOriginal->opcion_hotel_tarifa_id !== null && isset($tarifasHotelClonadas[$itemOriginal->opcion_hotel_tarifa_id])) {
                $cambios['opcion_hotel_tarifa_id'] = $tarifasHotelClonadas[$itemOriginal->opcion_hotel_tarifa_id];
            }
            // 07-sep-2026 — mismo criterio, para un ítem que materializa
            // un OpcionMayoristaOpcional elegido (ver crearItemMayorista()).
            if ($itemOriginal->opcion_mayorista_opcional_id !== null && isset($opcionalesClonados[$itemOriginal->opcion_mayorista_opcional_id])) {
                $cambios['opcion_mayorista_opcional_id'] = $opcionalesClonados[$itemOriginal->opcion_mayorista_opcional_id];
            }
            if ($cambios !== []) {
                $itemsClonados[$itemOriginal->id]->update($cambios);
            }
        }


        return [$nueva, $itemsClonados];
    }
}
