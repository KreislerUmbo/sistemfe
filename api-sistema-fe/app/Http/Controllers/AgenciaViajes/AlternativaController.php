<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajeAereo;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use App\Services\AgenciaViajes\PriceEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Alternativa de cotización — plan-modulo-cotizaciones-reservas.md §3.1/§3.2.
// Anidada bajo cotización (store), directa por su propio id (update/destroy).
class AlternativaController extends Controller
{
    private const MAX_ALTERNATIVAS_POR_COTIZACION = 5;

    public function __construct(private PriceEngineService $priceEngine)
    {
    }

    public function store(Request $request, string $cotizacionId)
    {
        $cotizacion = Cotizacion::findOrFail($cotizacionId);

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:100',
            'moneda_cotizacion' => 'required|in:PEN,USD',
            'tipo_cambio_origen' => 'required|in:dia,agencia',
            // Opcional: si se manda, registra un tipo_cambio_agencia NUEVO con
            // este valor (§3.4, "opción de digitar uno nuevo si no está
            // registrado todavía") en vez de reusar el último ya existente.
            'tipo_cambio_valor' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        if ($cotizacion->alternativas()->count() >= self::MAX_ALTERNATIVAS_POR_COTIZACION) {
            return response()->json([
                'code' => 422,
                'message' => 'Esta cotización ya tiene el máximo de ' . self::MAX_ALTERNATIVAS_POR_COTIZACION . ' alternativas permitidas.',
            ], 422);
        }

        $validado = $validator->validated();

        $tipoCambio = $this->resolverTipoCambio($validado['tipo_cambio_origen'], $validado['tipo_cambio_valor'] ?? null, $request);
        if ($tipoCambio instanceof JsonResponse) {
            return $tipoCambio;
        }

        $letra = chr(65 + $cotizacion->alternativas()->count()); // A, B, C...

        $alternativa = DB::transaction(function () use ($cotizacion, $validado, $tipoCambio, $letra) {
            $alternativa = Alternativa::create([
                'cotizacion_id' => $cotizacion->id,
                // 29-ago-2026 — capitalización tipo título solo cuando el
                // usuario tipeó algo; el fallback autogenerado ya está bien
                // formado, no hace falta pasarlo por la función.
                'nombre' => $validado['nombre'] ? \App\Services\TextoFormatoService::capitalizarNombrePropio($validado['nombre']) : "Alternativa {$letra}",
                'estado' => 'borrador',
                'moneda_cotizacion' => $validado['moneda_cotizacion'],
                'tipo_cambio_aplicado' => $tipoCambio->valor,
                'tipo_cambio_origen' => $validado['tipo_cambio_origen'],
                'total' => 0,
            ]);

            // Sesión 12c (auditoria-arquitectonica-agencia-viajes.md §7/FASE
            // 2) — toda alternativa nace con exactamente 1 destino, igual
            // que el backfill de 12b dejó a las históricas. Sin esto, una
            // alternativa creada de acá en adelante quedaría con 0 filas en
            // alternativa_destinos, rompiendo esa garantía.
            AlternativaDestino::create([
                'alternativa_id' => $alternativa->id,
                'destino_atractivo_id' => AlternativaDestino::resolverDestinoAtractivoId($cotizacion->destino),
                'destino_texto' => $cotizacion->destino,
                'orden' => 1,
                'fecha_inicio' => $cotizacion->fecha_viaje_desde,
                'fecha_fin' => $cotizacion->fecha_viaje_hasta,
            ]);

            return $alternativa;
        });

        return response()->json([
            'code' => 200,
            'message' => 'Alternativa creada correctamente',
            'alternativa' => $alternativa,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $alternativa = Alternativa::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'nombre' => 'nullable|string|max:100',
            'estado' => 'nullable|in:borrador,enviada,aceptada,descartada',
            'descuento_global_pct' => 'nullable|numeric|min:0|max:100',
            // Sesión 11i — alternativa al de arriba cuando configuracion_agencia.
            // modo_descuento_global='monto'. El frontend manda uno u otro,
            // nunca los dos en el mismo request (un solo input visible según
            // el modo configurado) — no se valida la exclusión mutua acá
            // porque no hay forma de que ambos lleguen juntos desde la UI.
            'descuento_global_monto' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = array_filter($validator->validated(), fn ($v) => $v !== null);
        // 29-ago-2026 — capitalización tipo título solo si vino en el
        // payload (array_filter ya sacó los null, así que isset alcanza).
        if (isset($validado['nombre'])) {
            $validado['nombre'] = \App\Services\TextoFormatoService::capitalizarNombrePropio($validado['nombre']);
        }

        // Sesión 12a (Fase 0, auditoría §3.2) — único camino documentado
        // donde el total de una alternativa ya aceptada (con su reserva ya
        // creada) podía cambiar en vivo sin que nadie lo note. Mismo
        // patrón/mensaje ya usado en AlternativaItemController::reasignarDia()/
        // moverBloque() para el mismo escenario ("alternativa aceptada, no
        // tocar acá").
        if ($alternativa->estado === 'aceptada'
            && (array_key_exists('descuento_global_pct', $validado) || array_key_exists('descuento_global_monto', $validado))) {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa ya fue aceptada y generó una reserva — el descuento global ya no se puede modificar desde acá.',
            ], 422);
        }

        $lineasFueraDePiso = [];

        DB::transaction(function () use ($alternativa, $validado, &$lineasFueraDePiso) {
            if (($validado['estado'] ?? null) === 'enviada' && ! $alternativa->fecha_envio) {
                $validado['fecha_envio'] = now();
            }

            // descuento_global_monto no es columna real de `alternativas` —
            // se resuelve a un descuento_global_pct efectivo ANTES del
            // update() genérico, para que la columna real quede consistente
            // sin importar por cuál input entró el descuento.
            $montoGlobal = $validado['descuento_global_monto'] ?? null;
            unset($validado['descuento_global_monto']);

            $alternativa->update($validado);

            // Aceptar una alternativa descarta automáticamente las demás de
            // la misma cotización — §3.2. Este PUT ya NO dispara creación de
            // reserva (Sesión 11c usa POST alternativas/{id}/aceptar,
            // ReservaController::aceptar(), que reusa descartarOtras() de
            // acá mismo — no se duplica esta lógica).
            if (($validado['estado'] ?? null) === 'aceptada') {
                self::descartarOtras($alternativa);
            }

            // §3.1 — "al aplicarse, se reparte a cada alternativa_items
            // respetando el piso individual de cada uno; si alguna línea no
            // lo permite, se avisa cuál en vez de bloquear todo en
            // silencio". Antes de esta sesión el campo se guardaba en la
            // alternativa pero nunca se aplicaba a ningún ítem — gap real
            // encontrado al conectar el panel de precio (Parte B, 11b3).
            if ($montoGlobal !== null) {
                $lineasFueraDePiso = $this->aplicarDescuentoGlobalMonto($alternativa, (float) $montoGlobal);
            } elseif (array_key_exists('descuento_global_pct', $validado)) {
                $lineasFueraDePiso = $this->aplicarDescuentoGlobal($alternativa, (float) $validado['descuento_global_pct']);
            }
        });

        return response()->json([
            'code' => 200,
            'message' => 'Alternativa actualizada correctamente',
            'alternativa' => $alternativa->fresh('items'),
            'lineas_fuera_de_piso' => $lineasFueraDePiso,
        ]);
    }

    // Reparte descuentoGlobalPct a CADA ítem de la alternativa (mismo % para
    // todos, sobre su propio precio de lista) — mismo motor matemático que
    // AlternativaItemController::update() ya usa para un ítem suelto
    // (PriceEngineService::evaluarPiso()), sin reimplementar la fórmula.
    // Mismo criterio ya establecido ahí: el piso NUNCA bloquea el guardado,
    // solo se informa qué líneas lo cruzan — el descuento global se aplica
    // igual a todas, el vendedor decide si corrige alguna a mano después.
    // Ítems sin proveedor_tarifa (manual/referencia) no tienen piso que
    // evaluar, pero SÍ reciben el descuento — "cada alternativa_items" del
    // plan no distingue por origen_tipo.
    //
    // Sesión 11i — el hallazgo original que pedía envolver este foreach en
    // una transacción propia ya estaba resuelto desde 11b3: update() (el
    // único caller) ya envuelve TODO en su propio DB::transaction(), así
    // que este método nunca corrió sin transacción en el código real. El
    // DB::transaction() de acá abajo es hardening defensivo (savepoint
    // anidado, sin costo real) para que el método sea seguro por sí mismo
    // aunque un caller futuro lo invoque sin transacción propia — no es la
    // corrección de un bug pendiente.
    // Sesión M4 — público (no private) para que AlternativaItemController::
    // elegirOpcionGrupo() pueda reaplicar el descuento global vigente sobre
    // la fila recién elegida, en vez de dejarla a precio de lista hasta que
    // el vendedor vuelva a tocar el campo de descuento. Mismo patrón de
    // reuso cross-controller que ReservaController::resolverNombreItem().
    public function aplicarDescuentoGlobal(Alternativa $alternativa, float $descuentoGlobalPct): array
    {
        return DB::transaction(function () use ($alternativa, $descuentoGlobalPct) {
            $lineasFueraDePiso = [];

            $items = $alternativa->items()->with('proveedorTarifa')->get();
            ['sinGrupo' => $sinGrupo, 'grupos' => $grupos] = AlternativaItem::agruparPorGrupoOpcion($items);

            // Sesión M1 (Ronda 2/P6) — solo reciben el descuento global los
            // ítems sin grupo de siempre, más la fila opcion_elegida=true
            // de cada grupo YA resuelto. El resto de cada grupo (un grupo
            // entero abierto, o las filas no elegidas de uno resuelto)
            // siempre queda a precio de lista, sin descuento — no hay una
            // sola línea válida sobre la que aplicar % hasta que el
            // vendedor resuelva cuál eligió el pasajero.
            $itemsConDescuento = $sinGrupo;
            $itemsSinDescuento = collect();

            foreach ($grupos as $grupo) {
                $elegida = $grupo['items']->firstWhere('opcion_elegida', true);

                if ($elegida) {
                    $itemsConDescuento->push($elegida);
                    $itemsSinDescuento = $itemsSinDescuento->merge($grupo['items']->reject(fn (AlternativaItem $i) => $i->is($elegida)));
                } else {
                    $itemsSinDescuento = $itemsSinDescuento->merge($grupo['items']);
                }
            }

            foreach ($itemsConDescuento as $item) {
                $precioListaConvertido = $this->priceEngine->convertirMoneda(
                    (float) $item->precio_venta_snapshot,
                    $item->moneda_costo,
                    $alternativa->moneda_cotizacion,
                    (float) $alternativa->tipo_cambio_aplicado
                );

                $precioConvertido = round($precioListaConvertido * (1 - $descuentoGlobalPct / 100), 2);

                if ($item->proveedorTarifa) {
                    $tarifa = $item->proveedorTarifa;
                    $costoBaseConvertido = $this->priceEngine->convertirMoneda(
                        (float) $item->costo_snapshot,
                        $item->moneda_costo,
                        $alternativa->moneda_cotizacion,
                        (float) $alternativa->tipo_cambio_aplicado
                    );

                    $pisoInfo = $this->priceEngine->evaluarPiso(
                        precioEditado: $precioConvertido,
                        costoBase: $costoBaseConvertido,
                        ventaBase: $precioListaConvertido,
                        descuentoMaximoPct: $tarifa->descuento_maximo_pct !== null ? (float) $tarifa->descuento_maximo_pct : null,
                        margenMinimoPct: $tarifa->margen_minimo_pct !== null ? (float) $tarifa->margen_minimo_pct : null,
                    );

                    if ($pisoInfo['alerta_piso']) {
                        $lineasFueraDePiso[] = [
                            'alternativa_item_id' => $item->id,
                            'precio_minimo_permitido' => $pisoInfo['precio_minimo_permitido'],
                        ];
                    }
                }

                $item->update([
                    'descuento_pct' => $descuentoGlobalPct,
                    'precio_convertido' => $precioConvertido,
                ]);
            }

            // Sesión M1 — se fuerzan a precio de lista, sin %, cada vez que
            // se reaplica el descuento global — así calcularTotalEfectivo()
            // puede confiar en que total_convertido de un ítem de grupo
            // excluido SIEMPRE es su precio de lista convertido, sin tener
            // que reconvertir moneda de nuevo para el mínimo de un grupo
            // abierto.
            foreach ($itemsSinDescuento as $item) {
                $precioListaConvertido = $this->priceEngine->convertirMoneda(
                    (float) $item->precio_venta_snapshot,
                    $item->moneda_costo,
                    $alternativa->moneda_cotizacion,
                    (float) $alternativa->tipo_cambio_aplicado
                );

                $item->update([
                    'descuento_pct' => 0,
                    'precio_convertido' => round($precioListaConvertido, 2),
                ]);
            }

            $total = AlternativaItem::calcularTotalEfectivo($alternativa->items()->get())['total'];
            $alternativa->update(['total' => $total]);

            return $lineasFueraDePiso;
        });
    }

    // Versión en MONTO de aplicarDescuentoGlobal() — Sesión 11i,
    // configuracion_agencia.modo_descuento_global='monto'. El plan pide
    // repartir el monto entre las líneas proporcional al peso de cada una
    // en el total (línea_i recibe monto * precio_i/precio_total). Eso es
    // matemáticamente EQUIVALENTE a un único % efectivo para TODA línea
    // (monto_i/precio_i = monto/precio_total, constante, no depende del
    // peso individual de cada línea) — así que en vez de reimplementar el
    // mismo foreach + evaluarPiso() una segunda vez, se resuelve el %
    // efectivo y se delega en aplicarDescuentoGlobal(), que ya lo hace
    // (mismo motor, mismo piso, una sola fórmula real en el código).
    // DB::transaction() propio por el mismo motivo defensivo que el de
    // arriba — anidado sin costo real sobre el de update()/aplicarDescuentoGlobal().
    private function aplicarDescuentoGlobalMonto(Alternativa $alternativa, float $montoGlobal): array
    {
        return DB::transaction(function () use ($alternativa, $montoGlobal) {
            $precioListaConvertidoDe = fn (AlternativaItem $item) => $this->priceEngine->convertirMoneda(
                (float) $item->precio_venta_snapshot,
                $item->moneda_costo,
                $alternativa->moneda_cotizacion,
                (float) $alternativa->tipo_cambio_aplicado
            );

            // Sesión M1 — mismo criterio de agrupación que
            // aplicarDescuentoGlobal(): un grupo resuelto cuenta solo su
            // elegida, uno abierto cuenta su mínimo una sola vez — sin
            // esto, sumaPreciosLista (y por lo tanto el % efectivo
            // calculado a partir de un monto) sumaría de más contando
            // TODAS las opciones descartadas del grupo.
            ['sinGrupo' => $sinGrupo, 'grupos' => $grupos] = AlternativaItem::agruparPorGrupoOpcion($alternativa->items()->get());

            $sumaPreciosLista = $sinGrupo->sum($precioListaConvertidoDe);

            foreach ($grupos as $grupo) {
                $elegida = $grupo['items']->firstWhere('opcion_elegida', true);
                $sumaPreciosLista += $elegida
                    ? $precioListaConvertidoDe($elegida)
                    : $grupo['items']->map($precioListaConvertidoDe)->min();
            }

            $pctEfectivo = $sumaPreciosLista > 0 ? round(($montoGlobal / $sumaPreciosLista) * 100, 4) : 0.0;

            // aplicarDescuentoGlobal() actualiza cada ÍTEM y el total de la
            // alternativa, pero nunca toca alternativas.descuento_global_pct
            // (esa columna la escribe update() directo desde el payload en
            // el camino de %) — sin esto, la columna se queda con el valor
            // viejo aunque todos los ítems ya reflejen el % efectivo nuevo.
            // update()['total'=>...] al final de aplicarDescuentoGlobal()
            // hace save() del modelo completo, así que este atributo sucio
            // se persiste junto con 'total' en el mismo UPDATE.
            $alternativa->descuento_global_pct = $pctEfectivo;

            return $this->aplicarDescuentoGlobal($alternativa, $pctEfectivo);
        });
    }

    // Antes de esta sesión, delete() era directo — con ítems/opciones-mayorista
    // ya cargados, `alternativa_items.alternativa_id`/`opcion_mayorista.
    // alternativa_id` (ambos `constrained()` sin `onDelete`, RESTRICT en
    // Postgres) tiraban una violación de FK sin capturar (500 crudo). El
    // frontend (editar.vue::eliminarAlternativa(), sin try/catch hasta esta
    // sesión) no mostraba nada — parecía que el botón "no hacía nada". Ahora:
    // 422 explícito si ya generó una reserva (no se puede perder ese
    // vínculo); si no, cascada real de todo lo que cuelga de la alternativa
    // (ítems + su cotizacion_pasaje_aereo, y las opciones de mayorista con
    // sus opcionales/hoteles/tarifas propias) en una transacción, mismo
    // patrón que PaquetePlantillaController::destroy().
    public function destroy(string $id)
    {
        $alternativa = Alternativa::findOrFail($id);

        if (Reserva::where('alternativa_id', $alternativa->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar esta alternativa: ya generó una reserva.',
            ], 422);
        }

        DB::transaction(fn () => self::eliminarCascada($alternativa));

        return response()->json(['code' => 200, 'message' => 'Alternativa eliminada correctamente']);
    }

    // Extraída de destroy() para que CotizacionController::destroy() (borra
    // TODAS las alternativas de una cotización) reuse la MISMA cascada en
    // vez de reescribirla — un solo lugar con la lista real de qué cuelga
    // de una alternativa. Sin guard ni transacción propia acá adentro: cada
    // caller decide su propio criterio de guard (acá, una sola alternativa
    // con reserva; en CotizacionController, cualquiera de las alternativas
    // de la cotización con reserva) y envuelve la llamada en su propia
    // transacción.
    public static function eliminarCascada(Alternativa $alternativa): void
    {
        $itemIds = AlternativaItem::where('alternativa_id', $alternativa->id)->pluck('id');
        CotizacionPasajeAereo::whereIn('alternativa_item_id', $itemIds)->delete();
        AlternativaItem::whereIn('id', $itemIds)->delete();

        // Sesión 12f-2 (multidestino) — bug real encontrado en verificación
        // en vivo de M4: alternativa_destinos nunca se borraba acá, así que
        // cualquier alternativa que hubiera llegado a tener un destino
        // (aunque fuera el único/por defecto) dejaba una FK huérfana que
        // rompía destroy() con un 500 (alternativa_destinos_alternativa_id_foreign).
        AlternativaDestino::where('alternativa_id', $alternativa->id)->delete();

        $opciones = OpcionMayorista::where('alternativa_id', $alternativa->id)->get();
        foreach ($opciones as $opcion) {
            OpcionMayoristaOpcional::where('opcion_mayorista_id', $opcion->id)->delete();

            $hotelIds = OpcionHotel::where('opcion_mayorista_id', $opcion->id)->pluck('id');
            OpcionHotelTarifa::whereIn('opcion_hotel_id', $hotelIds)->delete();
            OpcionHotel::whereIn('id', $hotelIds)->delete();

            $opcion->delete();
        }

        $alternativa->delete();
    }

    // Sesión 11h — clona la alternativa activa completa (ítems + su
    // cotizacion_pasaje_aereo si aplica, y el árbol de opciones de
    // mayorista con hoteles/tarifas/opcionales) en una alternativa NUEVA de
    // la misma cotización, nombre + " (copia)". Clonar el árbol de
    // opciones de mayorista (en vez de solo remapear alternativa_items al
    // original) es a propósito: la idea de "duplicar" es poder editar la
    // copia libremente sin afectar la alternativa original, y un ítem
    // origen_tipo=mayorista que siguiera apuntando a la opción del
    // original rompería esa independencia (el comparador de la copia no la
    // listaría como propia, ver OpcionMayoristaController::index()).
    // Mismo límite de 5 alternativas que store() — duplicar no lo esquiva.
    public function duplicar(string $id)
    {
        $original = Alternativa::findOrFail($id);

        if ($original->cotizacion->alternativas()->count() >= self::MAX_ALTERNATIVAS_POR_COTIZACION) {
            return response()->json([
                'code' => 422,
                'message' => 'Esta cotización ya tiene el máximo de ' . self::MAX_ALTERNATIVAS_POR_COTIZACION . ' alternativas permitidas.',
            ], 422);
        }

        $nueva = DB::transaction(function () use ($original) {
            $nueva = Alternativa::create([
                'cotizacion_id' => $original->cotizacion_id,
                'nombre' => $original->nombre . ' (copia)',
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

            // Ítems primero (sin opcion_mayorista_id todavía si vienen de
            // mayorista — se remapea al final, una vez clonado el árbol de
            // opciones más abajo).
            $itemsClonados = [];
            foreach ($original->items()->get() as $item) {
                $nuevoItem = AlternativaItem::create([
                    'alternativa_id' => $nueva->id,
                    'alternativa_destino_id' => $item->alternativa_destino_id !== null
                        ? ($destinosClonados[$item->alternativa_destino_id] ?? null)
                        : null,
                    'origen_tipo' => $item->origen_tipo,
                    'proveedor_tarifa_id' => $item->proveedor_tarifa_id,
                    'tour_origen_id' => $item->tour_origen_id,
                    'dia_referencial' => $item->dia_referencial,
                    'descripcion_manual' => $item->descripcion_manual,
                    'modo_precio' => $item->modo_precio,
                    'cantidad' => $item->cantidad,
                    'pax_incluidos' => $item->pax_incluidos,
                    'moneda_costo' => $item->moneda_costo,
                    'costo_snapshot' => $item->costo_snapshot,
                    'precio_venta_snapshot' => $item->precio_venta_snapshot,
                    'descuento_pct' => $item->descuento_pct,
                    'precio_convertido' => $item->precio_convertido,
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
                            'tip_afe_igv' => $pasaje->tip_afe_igv,
                            'fecha_cotizado' => $pasaje->fecha_cotizado,
                            'costo_total' => $pasaje->costo_total,
                            'precio_venta_total' => $pasaje->precio_venta_total,
                        ]);
                    }
                }
            }

            $opcionesClonadas = [];
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
                    OpcionMayoristaOpcional::create([
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
                }

                foreach (OpcionHotel::where('opcion_mayorista_id', $opcion->id)->get() as $hotel) {
                    $nuevoHotel = OpcionHotel::create([
                        'opcion_mayorista_id' => $nuevaOpcion->id,
                        'paquete_plantilla_id' => $hotel->paquete_plantilla_id,
                        'proveedor_id' => $hotel->proveedor_id,
                        'nombre_hotel' => $hotel->nombre_hotel,
                        'categoria_estrellas' => $hotel->categoria_estrellas,
                    ]);

                    foreach (OpcionHotelTarifa::where('opcion_hotel_id', $hotel->id)->get() as $tarifa) {
                        OpcionHotelTarifa::create([
                            'opcion_hotel_id' => $nuevoHotel->id,
                            'tipo_habitacion' => $tarifa->tipo_habitacion,
                            'precio_costo' => $tarifa->precio_costo,
                            'precio_venta' => $tarifa->precio_venta,
                        ]);
                    }
                }
            }

            foreach ($original->items()->where('origen_tipo', AlternativaItem::ORIGEN_MAYORISTA)->get() as $itemOriginal) {
                if (isset($itemsClonados[$itemOriginal->id], $opcionesClonadas[$itemOriginal->opcion_mayorista_id])) {
                    $itemsClonados[$itemOriginal->id]->update([
                        'opcion_mayorista_id' => $opcionesClonadas[$itemOriginal->opcion_mayorista_id],
                    ]);
                }
            }

            return $nueva;
        });

        return response()->json([
            'code' => 200,
            'message' => 'Alternativa duplicada correctamente',
            'alternativa' => $nueva->fresh('items'),
        ]);
    }

    // Sesión pdf-cotizacion — PDF comercial de UNA alternativa (nunca mezcla
    // ítems de otras alternativas de la misma cotización, decisión de diseño
    // #1 del plan). Groundwork de la sesión anterior (feature/perfil-agencia:
    // Company.logo_vertical/horizontal, cuentas_bancarias,
    // configuracion_agencia.condiciones_generales_servicio) es lo que este
    // método finalmente consume.
    public function pdf(string $id)
    {
        $alternativa = Alternativa::with([
            'cotizacion.cliente',
            'destinos.destinoAtractivo',
            'items.proveedorTarifa.proveedorServicio.destinoServicio.servicio',
            'items.proveedorTarifa.proveedorServicio.destinoServicio.destinoAtractivo',
            'items.proveedorTarifa.proveedorServicio.proveedor',
            'items.guiaTarifa.guia',
            'items.cotizacionPasajeAereo',
            'items.opcionMayorista.proveedor',
            // Sesión M2 — resolverNombreItem() con audiencia 'cliente'
            // también intenta el hotel de la matriz de un OpcionMayorista
            // (sin ProveedorTarifa real) antes de caer al genérico.
            'items.opcionHotelTarifa.opcionHotel',
        ])->findOrFail($id);

        $config = \App\Models\AgenciaViajes\ConfiguracionAgencia::first();
        $empresa = \App\Models\Company::first();
        $cuentasBancarias = \App\Models\AgenciaViajes\CuentaBancaria::where('activo', true)
            ->orderBy('orden')->orderBy('id')->get();

        $pasajeros = \App\Models\AgenciaViajes\CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)->get();

        $itinerario = $this->itinerarioAlternativa($alternativa);
        $incluyePorDestino = $this->incluyePorDestino($alternativa);
        $tourUnico = $this->tourUnicoDeAlternativa($alternativa);

        // Sesión 12f-3 — el PDF comercial deja de mostrar precio por ítem
        // (decisión del usuario, ver brief 12f3 §0.3): estos totales siguen
        // calculándose igual que antes, solo que ya no viaja un $items con
        // precio por fila a la vista — la tabla de "Precio" quedó reducida
        // al bloque de totales.
        $totalOriginal = $alternativa->items->sum(
            fn (AlternativaItem $item) => (float) $item->precio_venta_snapshot * (float) $item->cantidad
        );
        $total = (float) $alternativa->total;
        $descuentoMonto = round($totalOriginal - $total, 2);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.agencia-viajes.alternativa', [
            'alternativa' => $alternativa,
            'cotizacion' => $alternativa->cotizacion,
            'cliente' => $alternativa->cotizacion->cliente,
            'empresa' => $empresa,
            // resolveParaPdf(), no resolve() — DomPDF corre con
            // enable_remote=false, así que la URL de resolve() (pensada
            // para el navegador) nunca carga como imagen embebida acá
            // dentro. Mismo bug ya corregido en SaleController/NotaController/
            // PaymentReceiptController/CommercialQuoteController/
            // ReporteOperativoController — quedaba pendiente acá a propósito
            // (29-ago-2026).
            'logoUrl' => \App\Services\StorageUrl::resolveParaPdf($empresa?->logo_horizontal),
            'config' => $config,
            'cuentasBancarias' => $cuentasBancarias,
            'pasajeros' => $pasajeros,
            'itinerario' => $itinerario,
            'incluyePorDestino' => $incluyePorDestino,
            'tourUnico' => $tourUnico,
            'total' => $total,
            'totalOriginal' => $totalOriginal,
            'descuentoMonto' => $descuentoMonto,
            'hayDescuento' => $descuentoMonto > 0.01,
        ]);

        $nombreArchivo = 'Cotizacion-' . ($alternativa->cotizacion->codigo ?? $alternativa->cotizacion_id) . '-' . \Illuminate\Support\Str::slug($alternativa->nombre) . '.pdf';

        return $pdf->download($nombreArchivo);
    }

    // Si TODOS los ítems de la alternativa comparten el mismo tour_origen_id
    // (caso más común: una alternativa = un tour), devuelve ese
    // PaquetePlantilla para poder mostrar no_incluye/recomendaciones/
    // lugar_recojo/horarios. Si hay ítems de varios tours distintos, o
    // ítems sin tour_origen_id mezclados con ítems que sí lo tienen, NO hay
    // "el tour" de esta alternativa — devuelve null a propósito, ver
    // decisión de diseño #4 (no concatenar texto de varios tours).
    private function tourUnicoDeAlternativa(Alternativa $alternativa): ?\App\Models\AgenciaViajes\PaquetePlantilla
    {
        $tourIds = $alternativa->items->pluck('tour_origen_id')->unique();

        if ($tourIds->count() !== 1 || $tourIds->first() === null) {
            return null;
        }

        return \App\Models\AgenciaViajes\PaquetePlantilla::find($tourIds->first());
    }

    // Sesión 12f-3 — agrupa los AlternativaItem de la alternativa por
    // destino real (alternativa_destino_id), tratando null como
    // perteneciente al PRIMER destino (mismo fallback que
    // itemsDelDestinoActivo en editar.vue, 12f-2, por los ítems legacy que
    // 12c dejó sin alternativa_destino_id resuelto). Compartido por
    // itinerarioAlternativa()/incluyePorDestino() para no duplicar el
    // criterio de fallback en dos lados. $alternativa->destinos siempre
    // trae al menos 1 fila (garantía de 12b/12c/12f-2, destroy() bloquea
    // borrar el último) — no hay caso real de array vacío acá.
    private function itemsPorDestino(Alternativa $alternativa): array
    {
        $destinos = $alternativa->destinos;

        if ($destinos->isEmpty()) {
            return [];
        }

        $primerDestinoId = $destinos->first()->id;

        return $destinos->map(fn (AlternativaDestino $destino) => [
            'destino' => $destino,
            'items' => $alternativa->items->filter(
                fn (AlternativaItem $item) => ($item->alternativa_destino_id ?? $primerDestinoId) === $destino->id
            )->values(),
        ])->values()->all();
    }

    // Itinerario narrativo — concatena el paqueteItinerario() de cada tour
    // DISTINTO presente en los ítems de CADA destino, en el orden en que
    // aparecen, con offset de día que se reinicia al entrar a un destino
    // nuevo (mismo criterio que dia_referencial en el cotizador desde
    // 12f-2). Generaliza ComboExplosionService::itinerarioDerivado()
    // (pensado para un combo específico) a "los tours que realmente
    // aparecen acá", porque una alternativa puede mezclar tours sin venir
    // de un combo formal. Devuelve un array de BLOQUES (uno por destino
    // con al menos un paso), no un array plano de pasos — 12f-3, antes era
    // plano y asumía un solo destino.
    private function itinerarioAlternativa(Alternativa $alternativa): array
    {
        $bloques = [];

        foreach ($this->itemsPorDestino($alternativa) as $grupo) {
            $destino = $grupo['destino'];
            $tourIds = $grupo['items']->pluck('tour_origen_id')->filter()->unique()->values();

            if ($tourIds->isEmpty()) {
                continue;
            }

            $pasos = [];
            $offsetDia = 0;

            foreach ($tourIds as $tourId) {
                $tour = \App\Models\AgenciaViajes\PaquetePlantilla::find($tourId);
                if (! $tour) {
                    continue;
                }

                $pasosDelTour = $tour->paqueteItinerario()->orderBy('dia_relativo')->orderBy('orden')->get();
                $maxDiaDelTour = 0;

                foreach ($pasosDelTour as $paso) {
                    $pasos[] = [
                        'dia' => $offsetDia + $paso->dia_relativo,
                        'hora' => $paso->hora,
                        'descripcion' => $paso->descripcion,
                        'tour_nombre' => $tour->nombre,
                    ];
                    $maxDiaDelTour = max($maxDiaDelTour, $paso->dia_relativo);
                }

                $offsetDia += $maxDiaDelTour;
            }

            if (empty($pasos)) {
                continue;
            }

            $bloques[] = [
                'destino_id' => $destino->id,
                'destino_nombre' => $destino->destinoAtractivo?->nombre ?? $destino->destino_texto ?? 'Destino',
                'fecha_inicio' => $destino->fecha_inicio,
                'fecha_fin' => $destino->fecha_fin,
                'pasos' => $pasos,
            ];
        }

        return $bloques;
    }

    // Sesión 12f-3 — "Incluye" (lista de nombres, sin precio) agrupada por
    // destino con el mismo criterio que itinerarioAlternativa(). Un bloque
    // por destino con al menos 1 ítem.
    //
    // Hallazgo real 01-sep-2026 (revisión posterior a 12f-3, contra datos
    // reales de agencia-demo): un combo multi-día genera un AlternativaItem
    // POR CADA TOUR del día (ej. "Transporte / Traslado Ida y Vuelta" en el
    // tour del día 1, otra vez en el del día 2, otra vez en el del día 3) —
    // nombre() ya deduplicaba el itinerario (agrupa por tour_origen_id
    // único), pero "Incluye" mapeaba 1:1 cada ítem, así que el mismo texto
    // salía repetido varias veces seguidas en el PDF. Se deduplica acá por
    // nombre — decisión confirmada con el usuario: una sola línea, sin
    // contador "(3x)" (el itinerario de arriba ya cuenta la historia
    // completa día por día; "Incluye" es un checklist, no un log).
    private function incluyePorDestino(Alternativa $alternativa): array
    {
        $bloques = [];

        foreach ($this->itemsPorDestino($alternativa) as $grupo) {
            if ($grupo['items']->isEmpty()) {
                continue;
            }

            $destino = $grupo['destino'];

            $bloques[] = [
                'destino_id' => $destino->id,
                'destino_nombre' => $destino->destinoAtractivo?->nombre ?? $destino->destino_texto ?? 'Destino',
                'fecha_inicio' => $destino->fecha_inicio,
                'fecha_fin' => $destino->fecha_fin,
                'nombres' => $grupo['items']
                    ->map(fn (AlternativaItem $item) => ReservaController::resolverNombreItem($item, null, 'cliente'))
                    ->unique()
                    ->values(),
            ];
        }

        return $bloques;
    }

    // Compartido con ReservaController::aceptar() y VentaDirectaController::store()
    // (Sesión 11c) — descartar las demás alternativas de la misma cotización
    // es siempre el mismo movimiento sin importar quién marcó "aceptada".
    public static function descartarOtras(Alternativa $alternativa): void
    {
        Alternativa::where('cotizacion_id', $alternativa->cotizacion_id)
            ->where('id', '!=', $alternativa->id)
            ->update(['estado' => 'descartada']);
    }

    private function resolverTipoCambio(string $origen, ?float $valorNuevo, Request $request): TipoCambioAgencia|JsonResponse
    {
        if ($valorNuevo !== null) {
            return TipoCambioAgencia::create([
                'fecha' => now()->toDateString(),
                'origen' => $origen,
                'valor' => $valorNuevo,
                'registrado_por' => $request->user()->id,
            ]);
        }

        $ultimo = TipoCambioAgencia::where('origen', $origen)->orderByDesc('fecha')->orderByDesc('id')->first();

        if (! $ultimo) {
            return response()->json([
                'code' => 422,
                'message' => "No hay ningún tipo de cambio '{$origen}' registrado todavía — indicá tipo_cambio_valor para registrar uno nuevo.",
            ], 422);
        }

        return $ultimo;
    }
}
