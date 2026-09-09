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
use App\Services\AgenciaViajes\AlternativaPdfService;
use App\Services\AgenciaViajes\PriceEngineService;
use App\Services\TipoCambio\TipoCambioSanityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Alternativa de cotización — plan-modulo-cotizaciones-reservas.md §3.1/§3.2.
// Anidada bajo cotización (store), directa por su propio id (update/destroy).
class AlternativaController extends Controller
{
    private const MAX_ALTERNATIVAS_POR_COTIZACION = 5;

    public function __construct(
        private PriceEngineService $priceEngine,
        private AlternativaPdfService $alternativaPdfService,
        private TipoCambioSanityService $tipoCambioSanity,
    ) {
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
            // Segundo paso consciente si tipo_cambio_valor cae fuera del
            // rango de sanidad (2.0-6.0 USD/PEN) — ver TipoCambioSanityService.
            'tipo_cambio_confirmado' => 'nullable|boolean',
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

        $tipoCambio = $this->resolverTipoCambio($validado['tipo_cambio_origen'], $validado['tipo_cambio_valor'] ?? null, $request, $validado['tipo_cambio_confirmado'] ?? false);
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

        // Bug real (07-sep-2026, auditoría de mantenibilidad 05-sep-2026):
        // este PUT genérico aceptaba estado=aceptada y lo aplicaba (incluso
        // descartando las demás alternativas de la cotización, ver
        // descartarOtras() más abajo) SIN pasar por ReservaController::
        // aceptar() — el único lugar que realmente crea la Reserva. El
        // propio comentario de este método ya lo admitía ("Este PUT ya NO
        // dispara creación de reserva") sin bloquearlo — cualquier
        // consumidor que reuse este endpoint genérico en vez del POST
        // .../aceptar (un script, Postman, un copy-paste futuro) deja una
        // alternativa "aceptada" fantasma, sin Reserva asociada. El
        // frontend actual (marcarAceptada() en editar.vue) ya usa el
        // endpoint correcto — bloquear acá no le cambia nada.
        if (($validado['estado'] ?? null) === 'aceptada') {
            return response()->json([
                'code' => 422,
                'message' => 'Para aceptar una alternativa usá POST alternativas/{id}/aceptar — este endpoint no crea la reserva asociada.',
            ], 422);
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

            // Bug real (07-sep-2026) — esta rama nunca podía dispararse: el
            // 422 de arriba ya bloquea estado=aceptada antes de llegar
            // acá. La dejaba muerta (nunca ejecutaba descartarOtras() vía
            // este PUT) — se quita. descartarOtras() sigue viva, la sigue
            // usando ReservaController::aceptar() (POST .../aceptar), el
            // único lugar que de verdad crea la Reserva.

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
            $idsTarifaHotelAdhoc = $original->items()
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
            foreach ($original->items()->get() as $item) {
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
                    'grupo_opcion_id' => $item->grupo_opcion_id,
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
            foreach ($original->items()->get() as $itemOriginal) {
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
        return $this->alternativaPdfService->generar($id);
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

    private function resolverTipoCambio(string $origen, ?float $valorNuevo, Request $request, bool $confirmado = false): TipoCambioAgencia|JsonResponse
    {
        if ($valorNuevo !== null) {
            if (! $this->tipoCambioSanity->esRazonable($valorNuevo) && ! $confirmado) {
                return response()->json([
                    'code' => 422,
                    'message' => "El valor {$valorNuevo} está fuera del rango esperado para USD/PEN (" . TipoCambioSanityService::RANGO_MINIMO . '-' . TipoCambioSanityService::RANGO_MAXIMO . '). Si es correcto, reenviá con tipo_cambio_confirmado=true.',
                    'requiere_confirmacion' => true,
                ], 422);
            }

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
