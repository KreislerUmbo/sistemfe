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
use App\Models\AgenciaViajes\AfiliacionTurismo;
use App\Models\AgenciaViajes\ConfiguracionAgenciaPdf;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use App\Services\AgenciaViajes\ImagenRecorteService;
use App\Services\AgenciaViajes\PriceEngineService;
use App\Services\StorageUrl;
use App\Services\TextoFormatoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

// Alternativa de cotización — plan-modulo-cotizaciones-reservas.md §3.1/§3.2.
// Anidada bajo cotización (store), directa por su propio id (update/destroy).
class AlternativaController extends Controller
{
    private const MAX_ALTERNATIVAS_POR_COTIZACION = 5;

    public function __construct(
        private PriceEngineService $priceEngine,
        private ImagenRecorteService $imagenRecorte,
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
        $alternativa = Alternativa::with([
            'cotizacion.cliente',
            'destinos.destinoAtractivo',
            'items.proveedorTarifa.proveedorServicio.destinoServicio.servicio',
            'items.proveedorTarifa.proveedorServicio.destinoServicio.destinoAtractivo',
            'items.proveedorTarifa.proveedorServicio.proveedor',
            'items.guiaTarifa.guia',
            'items.cotizacionPasajeAereo',
            'items.opcionMayorista.proveedor',
            // Simulación Panamá (04-sep-2026) — Incluye/No incluye/Vuelo/
            // Tours opcionales del mayorista elegido, ver
            // mayoristasReferenciados().
            'items.opcionMayorista.opcionales',
            // Tours incluidos con itinerario real, ver itinerarioAlternativa().
            'items.opcionMayorista.tours.paquetePlantilla',
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
        $opcionesHoteles = $this->opcionesHoteles($alternativa);

        // Simulación Panamá (04-sep-2026) — Incluye/No incluye/Vuelo/Tours
        // opcionales de la(s) opción(es) de mayorista realmente elegidas en
        // esta alternativa (ver mayoristasReferenciados()).
        $mayoristas = $this->mayoristasReferenciados($alternativa);
        // TextoFormatoService::textoLibreParaPdf() — bug real 06-sep-2026,
        // viñetas de Wingdings/Symbol pegadas desde Word (Zona de Uso
        // Privado de Unicode) salían como "?" en el PDF.
        //
        // Editor de texto enriquecido (07-sep-2026, pedido del usuario): estos
        // 3 campos (incluye/no_incluye/vuelo_detalle) pasan de <textarea>
        // plano a RichTextEditor (Quill) en el frontend — ya no es
        // necesariamente texto con saltos de línea literales, puede ser
        // HTML (<p>/<ul><li>/<strong>). textoLibreParaPdf() cubre ambos
        // casos: si detecta HTML real lo renderiza tal cual (Quill ya trae
        // su propia estructura); si es texto plano (dato cargado ANTES de
        // este cambio) reconstruye el <ul><li> — sin esto, texto plano con
        // "\n" literales perdía sus viñetas al renderizarse crudo (bug real
        // encontrado generando el PDF de una cotización ya existente).
        $mayoristasIncluye = $mayoristas
            ->map(fn ($m) => TextoFormatoService::textoLibreParaPdf($m->incluye))
            ->filter(fn ($html) => $html !== '')
            ->values();
        $mayoristasNoIncluye = $mayoristas
            ->map(fn ($m) => TextoFormatoService::textoLibreParaPdf($m->no_incluye))
            ->filter(fn ($html) => $html !== '')
            ->values();
        $mayoristasVuelo = $mayoristas
            ->filter(fn ($m) => filled($m->vuelo_aerolinea))
            ->map(fn ($m) => [
                'aerolinea' => $m->vuelo_aerolinea,
                'detalle' => TextoFormatoService::textoLibreParaPdf($m->vuelo_detalle),
            ])
            ->values();
        $mayoristasOpcionales = $mayoristas->flatMap(fn ($m) => $m->opcionales)->values();

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

        // Mejora del PDF de cotización (plan-mejora-pdf-cotizacion-cliente.md)
        // — marca por agencia, fotos de portada/galería/hoteles, cinta de
        // categoría. Todo con defaults de sistema si el tenant no configuró
        // nada (§6 del plan): ConfiguracionAgenciaPdf::actual() nunca
        // devuelve null.
        $configPdf = ConfiguracionAgenciaPdf::actual();
        $categoria = $this->resolverCategoriaAlternativa($alternativa);
        $colorCategoria = $this->colorPorCategoria($categoria, $configPdf);
        $afiliaciones = $this->afiliacionesParaMostrar($configPdf);
        [$fotoPortadaPrincipal, $fotosPortadaSecundarias] = $this->fotosTourParaPdf($alternativa, $configPdf);
        $hotelesInfo = $this->hotelesInfoParaPdf($opcionesHoteles);

        // Hallazgo del usuario (06-sep-2026, comparando contra el mockup
        // aprobado): el header/footer custom (membrete real de la agencia)
        // se imprimía como contenido normal — subía/bajaba con el flujo de
        // la página en vez de quedar fijo arriba/abajo como una hoja
        // membretada real. Fix: position:fixed dentro del margen de @page
        // (misma técnica ya usada en reporte-operativo.blade.php,
        // ".marca-generacion") — el alto reservado se calcula acá porque
        // la imagen la sube la agencia con la proporción que quiera.
        $alturaHeaderMm = $this->alturaHeaderMm($configPdf, $afiliaciones);
        $alturaFooterMm = $this->alturaFooterMm($configPdf);

        // Pedido del usuario (06-sep-2026) — Poppins en vez de Arial. Se
        // registra ANTES de loadView(), no después: confirmado con el PDF
        // real (grep de "Poppins"/"Helvetica" dentro de los bytes del
        // archivo) que registrarlo después de loadView() no tenía efecto —
        // dompdf ya resuelve font-family contra las fuentes conocidas
        // durante el parseo del CSS (loadHtml(), disparado por loadView()),
        // no en el render() posterior. Hay que resolver una instancia
        // propia de PDF (no vía loadView() del facade, que crea la suya)
        // para poder tocar getDomPDF() antes de cargarle el HTML.
        $pdf = app('dompdf.wrapper');
        \App\Services\PdfFontService::registrarPoppins($pdf->getDomPDF());

        $pdf = $pdf->loadView('pdf.agencia-viajes.alternativa', [
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
            'configPdf' => $configPdf,
            'headerCustomUrl' => StorageUrl::resolveParaPdf($configPdf->imagen_header_custom),
            'footerCustomUrl' => StorageUrl::resolveParaPdf($configPdf->imagen_footer_custom),
            'alturaHeaderMm' => $alturaHeaderMm,
            'alturaFooterMm' => $alturaFooterMm,
            'afiliaciones' => $afiliaciones,
            'categoria' => $categoria,
            'colorCategoria' => $colorCategoria,
            'fotoPortadaPrincipal' => $fotoPortadaPrincipal,
            'fotosPortadaSecundarias' => $fotosPortadaSecundarias,
            'hotelesInfo' => $hotelesInfo,
            'cuentasBancarias' => $cuentasBancarias,
            'pasajeros' => $pasajeros,
            'itinerario' => $itinerario,
            'incluyePorDestino' => $incluyePorDestino,
            'tourUnico' => $tourUnico,
            'opcionesHoteles' => $opcionesHoteles,
            'mayoristasIncluye' => $mayoristasIncluye,
            'mayoristasNoIncluye' => $mayoristasNoIncluye,
            'mayoristasVuelo' => $mayoristasVuelo,
            'mayoristasOpcionales' => $mayoristasOpcionales,
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

    // Simulación Panamá (04-sep-2026) — OpcionMayorista distintas
    // referenciadas por los ítems de la alternativa (vía
    // AlternativaItem::opcionMayorista(), ya eager-cargada con
    // ->opcionales en pdf()). Los ítems de un grupo de hotel (Sesión M5)
    // solo se pueden agregar cuando la opción ya está 'elegida' (guard ya
    // existente en el frontend/backend) — todo lo que aparezca acá es por
    // definición la opción elegida, no hace falta filtrar por estado de
    // nuevo.
    private function mayoristasReferenciados(Alternativa $alternativa): \Illuminate\Support\Collection
    {
        return $alternativa->items
            ->map(fn (AlternativaItem $item) => $item->opcionMayorista)
            ->filter()
            ->unique('id')
            ->values();
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

            // Tours incluidos de un paquete de mayorista (OpcionMayoristaTour,
            // 04-sep-2026) — misma fuente de itinerario que un tour_origen_id
            // de Local/Nacional, solo que llega vía la opción de mayorista en
            // vez de directo del ítem. Se agregan DESPUÉS de los de
            // tour_origen_id (dedup), respetando 'orden' (el "Día" que ve el
            // vendedor) para la secuencia dentro de cada mayorista.
            $mayoristasDelGrupo = $grupo['items']->map(fn (AlternativaItem $item) => $item->opcionMayorista)->filter()->unique('id');
            foreach ($mayoristasDelGrupo as $opcionMayorista) {
                $tourIds = $tourIds->concat(
                    $opcionMayorista->tours()->orderBy('orden')->pluck('paquete_plantilla_id')
                );
            }
            $tourIds = $tourIds->unique()->values();

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

                // Hallazgo real (feedback del usuario sobre el PDF ya en
                // producción): 'destino_atractivo_id' es un campo
                // ESTRUCTURADO por paso ("Tio Yacu", "Baños Termales",
                // "Orquideario"...), independiente de la 'descripcion' en
                // texto libre — confirmado contra datos reales de
                // agencia-demo, el nombre del atractivo casi nunca se
                // repite tal cual dentro de la prosa de 'descripcion'. Sin
                // el eager-load y sin pasarlo a la vista, el PDF nunca lo
                // mostraba pese a que el dato ya está cargado por el
                // vendedor al armar el tour en el catálogo.
                $pasosDelTour = $tour->paqueteItinerario()->with('destinoAtractivo')->orderBy('dia_relativo')->orderBy('orden')->get();
                $maxDiaDelTour = 0;

                // Simulación Panamá (04-sep-2026) — fotos del tour, mismo
                // patrón que 'tour_nombre': se repiten por paso (el blade solo
                // las imprime una vez, en el primer paso del día) para no
                // duplicar la lógica de "una vez por tour" en la vista.
                //
                // Hallazgo del usuario (06-sep-2026): estas fotos se pasaban
                // como el ORIGINAL sin recortar (solo resolveParaPdf(), sin
                // ImagenRecorteService) — dompdf no conocía el tamaño real
                // hasta decodificar la imagen, y con fotos de celular de
                // proporción/resolución arbitraria terminaba pisando el
                // título del día siguiente ("el título de los tours es
                // montado por las imágenes"). Recorte 4:3 fijo (mismo
                // servicio que portada/galería/hoteles) elimina la
                // ambigüedad de tamaño — el blade además fija width/height
                // explícitos en el <img>, dompdf ya no tiene que adivinar.
                //
                // Máximo 3 fotos (pedido del usuario, 07-sep-2026) — mismo
                // criterio que la tira de hoteles (fachada + 1-2 de
                // habitación); un tour con muchas fotos cargadas no debe
                // generar un PDF de tamaño impredecible.
                $fotosDelTour = $this->imagenRecorte->recortarVariasParaPdf(array_slice($tour->fotos ?? [], 0, 3));

                foreach ($pasosDelTour as $paso) {
                    $pasos[] = [
                        'dia' => $offsetDia + $paso->dia_relativo,
                        'hora' => $paso->hora,
                        'descripcion' => TextoFormatoService::sanitizarHtmlParaPdf($paso->descripcion),
                        'tour_nombre' => $tour->nombre,
                        'tour_fotos' => $fotosDelTour,
                        'atractivo_nombre' => $paso->destinoAtractivo?->nombre,
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
            // Sesión M5 — encontrado renderizando el PDF real contra
            // agencia-demo: un ítem con grupo_opcion_id (matriz de
            // hoteles) ya aparece completo en su propia sección
            // "Opciones de hoteles" (tabla hotel × tipo de habitación,
            // ver opcionesHoteles() más abajo) — listarlo TAMBIÉN acá,
            // una línea por cada combinación hotel/habitación del grupo
            // (5 líneas en la verificación real), duplicaba la misma
            // información dos veces en el mismo documento. Los ítems
            // SIN grupo (el 100% de "Incluye" antes de este plan) siguen
            // exactamente igual.
            $itemsSinGrupoDeHotel = $grupo['items']->whereNull('grupo_opcion_id');

            if ($itemsSinGrupoDeHotel->isEmpty()) {
                continue;
            }

            $destino = $grupo['destino'];

            $bloques[] = [
                'destino_id' => $destino->id,
                'destino_nombre' => $destino->destinoAtractivo?->nombre ?? $destino->destino_texto ?? 'Destino',
                'fecha_inicio' => $destino->fecha_inicio,
                'fecha_fin' => $destino->fecha_fin,
                'nombres' => $itemsSinGrupoDeHotel
                    ->map(fn (AlternativaItem $item) => ReservaController::resolverNombreItem($item, null, 'cliente'))
                    ->unique()
                    ->values(),
            ];
        }

        return $bloques;
    }

    // Sesión M5 — sección "Opciones de hoteles" del PDF (plan-matriz-hoteles-
    // cotizador.md P10): un grupo de alternativa_items con grupo_opcion_id
    // se dibuja como tabla matriz (fila=hotel, columna=tipo de habitación),
    // igual formato que los 3 documentos reales que originaron este plan
    // (docs/auxiliares/). Reusa el resolver de nombre centralizado de M2
    // (ReservaController::resolverNombreItem(), audiencia 'cliente' — nunca
    // revela mayorista/proveedor, solo el hotel) en vez de reimplementar de
    // dónde sale el nombre del hotel: para cualquier ítem de Hotel esa
    // función SIEMPRE devuelve "{hotel} · {tipo_habitacion}" (confirmado
    // leyendo sus 2 ramas de hotel antes de escribir esto) — separar por
    // " · " alcanza, no hace falta duplicar la lógica de qué relación mirar
    // según origen_tipo/proveedor_tarifa_id/opcion_hotel_tarifa_id.
    private const ORDEN_TIPO_HABITACION = ['simple', 'matrimonial', 'doble', 'triple', 'familiar'];

    private function opcionesHoteles(Alternativa $alternativa): array
    {
        $grupos = AlternativaItem::agruparPorGrupoOpcion($alternativa->items)['grupos'];

        return $grupos->map(function (array $grupo) {
            $filasPorHotel = [];
            $tiposPresentes = [];

            foreach ($grupo['items'] as $item) {
                $nombreCompleto = ReservaController::resolverNombreItem($item, null, 'cliente');
                $partes = explode(' · ', $nombreCompleto, 2);

                // Un grupo de opciones es exclusivo de Hotel (P2 del
                // diseño) — cualquier ítem que no resuelva al formato
                // "hotel · tipo_habitacion" es un dato inesperado, se
                // omite de la tabla en vez de romper el PDF con un
                // índice inexistente.
                if (count($partes) !== 2) {
                    continue;
                }

                [$hotel, $tipoHabitacion] = $partes;
                $tiposPresentes[$tipoHabitacion] = true;

                $filasPorHotel[$hotel] ??= ['hotel' => $hotel, 'precios' => [], 'elegida' => false, 'opcion_hotel_id' => $item->opcionHotelTarifa?->opcion_hotel_id];
                $filasPorHotel[$hotel]['precios'][$tipoHabitacion] = (float) $item->precio_convertido;
                if ($item->opcion_elegida) {
                    $filasPorHotel[$hotel]['elegida'] = true;
                }
            }

            // ?: en vez de comparar contra `false` explícito hubiera sido un
            // bug real acá: array_search() devuelve 0 (índice legítimo) para
            // 'simple', el primer elemento del catálogo — 0 es falsy en PHP,
            // así que ?: 99 lo hubiera tratado como "no encontrado" y
            // mandado 'simple' al final en vez de primero (encontrado por
            // el test de esta sesión antes de mergear).
            $tiposHabitacion = collect(array_keys($tiposPresentes))
                ->sortBy(function (string $t) {
                    $posicion = array_search($t, self::ORDEN_TIPO_HABITACION, true);

                    return $posicion !== false ? $posicion : 99;
                })
                ->values()
                ->all();

            return [
                'grupo_opcion_id' => $grupo['grupo_opcion_id'],
                'tipos_habitacion' => $tiposHabitacion,
                'filas' => array_values($filasPorHotel),
                'resuelto' => collect($filasPorHotel)->contains('elegida', true),
            ];
        })->filter(fn (array $g) => count($g['filas']) > 0)->values()->all();
    }

    // Mejora del PDF de cotización (plan-mejora-pdf-cotizacion-cliente.md
    // §7) — la cinta de categoría usa la "más alta" presente en la
    // alternativa: internacional > nacional > local. Un ítem de mayorista
    // (opcion_mayorista_id) es SIEMPRE internacional por definición del
    // negocio (OpcionHotel::class docblock: "opcion_mayorista exclusivo de
    // paquetes internacionales con fecha fija") — no tiene
    // paquetes_plantilla.categoria propia porque no nace de un tour del
    // catálogo. Un ítem con tour_origen_id hereda la categoría de ESE tour.
    // Cualquier otro origen (manual/guía/pasaje aéreo/hotel local ad-hoc)
    // no tiene una señal de categoría propia — cae a 'nacional' (el color
    // intermedio por defecto), simplificación deliberada: no hay hoy un
    // campo de categoría a nivel de esos orígenes, y bloquear la cinta
    // hasta que alguien lo agregue sería peor que un default razonable.
    private function resolverCategoriaAlternativa(Alternativa $alternativa): string
    {
        $tourIds = $alternativa->items->pluck('tour_origen_id')->filter()->unique();
        $categoriasDeTours = $tourIds->isEmpty()
            ? collect()
            : PaquetePlantilla::whereIn('id', $tourIds)->pluck('categoria');

        $categorias = $alternativa->items->map(
            fn (AlternativaItem $item) => $item->opcion_mayorista_id ? 'internacional' : null
        )->filter()->merge($categoriasDeTours);

        foreach (['internacional', 'nacional', 'local'] as $prioridad) {
            if ($categorias->contains($prioridad)) {
                return $prioridad;
            }
        }

        return 'nacional';
    }

    private function colorPorCategoria(string $categoria, ConfiguracionAgenciaPdf $configPdf): string
    {
        return match ($categoria) {
            'internacional' => $configPdf->color_categoria_internacional,
            'local' => $configPdf->color_categoria_local,
            default => $configPdf->color_categoria_nacional,
        };
    }

    // plan §4.3 — solo arma la franja si mostrar_afiliaciones=true Y la
    // agencia marcó al menos una. AfiliacionTurismo vive en la base
    // central (CentralConnection) — un solo whereIn() para resolver todas
    // las marcadas, sin N+1.
    private function afiliacionesParaMostrar(ConfiguracionAgenciaPdf $configPdf): \Illuminate\Support\Collection
    {
        if (! $configPdf->mostrar_afiliaciones || ! $configPdf->exists) {
            return collect();
        }

        $marcadas = $configPdf->afiliaciones()->get();
        if ($marcadas->isEmpty()) {
            return collect();
        }

        $catalogo = AfiliacionTurismo::whereIn('id', $marcadas->pluck('afiliacion_id'))->get()->keyBy('id');

        return $marcadas->map(function ($m) use ($catalogo) {
            $afiliacion = $catalogo->get($m->afiliacion_id);

            return $afiliacion ? [
                'nombre' => $afiliacion->nombre,
                'logo' => StorageUrl::resolveParaPdf($afiliacion->logo_path),
            ] : null;
        })->filter()->values();
    }

    // Hallazgo del usuario (06-sep-2026) — hoja membretada real: el
    // header/footer debe quedar FIJO arriba/abajo de CADA página, no
    // subir/bajar con el contenido. dompdf soporta esto con
    // position:fixed dentro del margen reservado por @page (mismo truco
    // ya usado en reporte-operativo.blade.php) — pero el margen de @page
    // es un valor fijo en el CSS, así que hay que calcular acá cuánto
    // alto reservar según lo que realmente se va a imprimir arriba/abajo.
    //
    // Con imagen custom: la agencia sube su membrete con la proporción
    // que quiera (ver los 2 casos reales de DKM Xplore) — se mide el
    // archivo real y se calcula qué alto le corresponde al ancho
    // completo de la hoja A4 (el membrete hace bleed hasta el borde
    // físico, ver ANCHO_PAGINA_A4_MM en alturaBandaCompletaMm()).
    //
    // Sin imagen custom: el header/footer generado desde
    // ConfiguracionAgenciaPdf tiene un alto predecible (logo + 2 líneas
    // de contacto, +eslogan, +franja de afiliaciones) — reserva fija por
    // bloque presente, no medida en píxeles porque no hay ninguna imagen
    // que medir.
    private const ANCHO_PAGINA_A4_MM = 210.0;

    private function alturaHeaderMm(ConfiguracionAgenciaPdf $configPdf, \Illuminate\Support\Collection $afiliaciones): float
    {
        if ($configPdf->imagen_header_custom) {
            return $this->alturaBandaCompletaMm($configPdf->imagen_header_custom);
        }

        $altura = 28.0; // logo + nombre comercial + RUC/teléfono/email
        if (! empty($configPdf->eslogan)) {
            $altura += 4.0;
        }
        if ($afiliaciones->isNotEmpty()) {
            $altura += 10.0;
        }

        return $altura;
    }

    private function alturaFooterMm(ConfiguracionAgenciaPdf $configPdf): float
    {
        if ($configPdf->imagen_footer_custom) {
            // +14mm: el aviso de condiciones generales (footer-legal) va
            // SIEMPRE arriba del membrete custom, es contenido legal, no
            // branding — el override total del plan §4.2 solo reemplaza
            // eslogan/redes, no este aviso.
            return $this->alturaBandaCompletaMm($configPdf->imagen_footer_custom) + 14.0;
        }

        return empty($configPdf->redes_sociales) ? 16.0 : 22.0;
    }

    private function alturaBandaCompletaMm(string $path): float
    {
        if (! Storage::disk('public')->exists($path)) {
            return 20.0;
        }

        // getimagesize() alcanza acá — solo hace falta el ancho/alto real
        // del archivo, no manipular la imagen (eso ya lo hace
        // ImagenRecorteService para las fotos de tour/hotel).
        $medidas = @getimagesize(Storage::disk('public')->path($path));
        if (! $medidas || $medidas[0] <= 0) {
            return 20.0;
        }

        return round(self::ANCHO_PAGINA_A4_MM * ($medidas[1] / $medidas[0]), 1);
    }

    // plan §4.5 — portada (1 principal + hasta 2 secundarias) + galería de
    // itinerario (hasta 4), ambas desde el PRIMER tour CON FOTOS de la
    // alternativa (una alternativa multi-tour de todas formas necesita UN
    // solo set de fotos de portada, no uno por tour). Recorte 4:3 centrado
    // vía ImagenRecorteService, nunca el original (evita el estiramiento
    // que dompdf produciría con object-fit, que no soporta).
    //
    // Bug real (07-sep-2026, pedido del usuario "que aparezca en todo
    // caso"): esta función solo miraba alternativa_items.tour_origen_id
    // (Local/Nacional) — nunca revisaba los tours enganchados por
    // mayorista (Internacional, OpcionMayoristaTour), que es el 100% del
    // uso real de este tenant. Ahora arma la MISMA secuencia de tours que
    // ya usa itinerarioAlternativa() (tour_origen_id directo + tours de
    // mayorista por 'orden') y busca el PRIMERO que sí tenga foto_portada
    // marcada — no simplemente el primero de la lista: confirmado con
    // datos reales que "Día 1: Arribo a Cusco" no tenía portada marcada,
    // pero "Día 2: Tour Valle Sagrado..." sí — quedarse con el primero a
    // secas (como hacía antes) habría seguido sin mostrar nada.
    private function fotosTourParaPdf(Alternativa $alternativa, ConfiguracionAgenciaPdf $configPdf): array
    {
        if (! $configPdf->mostrar_fotos_tour) {
            return [null, []];
        }

        $tourIds = $alternativa->items->pluck('tour_origen_id')->filter()->values();

        foreach ($this->mayoristasReferenciados($alternativa) as $opcionMayorista) {
            $tourIds = $tourIds->concat(
                $opcionMayorista->tours()->orderBy('orden')->pluck('paquete_plantilla_id')
            );
        }

        $tour = $tourIds->unique()->values()
            ->map(fn ($tourId) => PaquetePlantilla::find($tourId))
            ->first(fn ($tour) => $tour && $tour->foto_portada);

        if (! $tour) {
            return [null, []];
        }

        // array_unique() (07-sep-2026, bug real encontrado con datos reales):
        // fotos_destacadas_pdf tenía la MISMA foto repetida dos veces (el
        // vendedor pudo haberla marcado "destacada" dos veces desde el
        // panel) — sin esto, las 2 secundarias de la portada podían
        // terminar mostrando la foto repetida en vez de dos fotos
        // distintas, según el orden del array.
        $destacadas = array_values(array_unique($tour->fotos_destacadas_pdf ?? []));
        $secundarias = array_values(array_diff($destacadas, [$tour->foto_portada]));

        // Pedido del usuario (07-sep-2026, con captura real: "veo más
        // imágenes duplicadas más abajo"): antes acá también se armaba una
        // sección "Galería de itinerario" con hasta 4 fotos destacadas,
        // aparte de la portada — pero esas MISMAS fotos ya se repetían en
        // las fotos por día del itinerario (fotosDelTour, agregado
        // 04-sep-2026, DESPUÉS de que se diseñara esta galería) y en la
        // propia portada. Con tours de pocas fotos (el caso real que
        // expuso el bug tenía solo 3 en total), la misma foto terminaba
        // saliendo 2-3 veces en el documento. Se quita la galería por
        // completo — portada + fotos por día ya cubren lo que la galería
        // pretendía mostrar, sin agregar nada nuevo.
        return [
            $this->imagenRecorte->recortar4x3ParaPdf($tour->foto_portada),
            $this->imagenRecorte->recortarVariasParaPdf(array_slice($secundarias, 0, 2)),
        ];
    }

    // plan §4.5 — sección "Fotos referenciales de los hoteles": por cada
    // hotel YA listado en la tabla de precios (opcionesHoteles(), que ahora
    // lleva 'opcion_hotel_id' por fila), su tira fachada+habitación(es) +
    // check-in/check-out si el hotel está ligado a un Proveedor real con
    // ProveedorAlojamientoDetalle cargado. Un hotel sin ninguna foto no
    // entra al mapa — el blade omite su bloque por completo (plan: "nunca
    // se deja un casillero en blanco").
    private function hotelesInfoParaPdf(array $opcionesHoteles): array
    {
        $idsHotel = collect($opcionesHoteles)
            ->flatMap(fn (array $grupo) => collect($grupo['filas'])->pluck('opcion_hotel_id'))
            ->filter()
            ->unique()
            ->values();

        if ($idsHotel->isEmpty()) {
            return [];
        }

        $hoteles = OpcionHotel::with('proveedor.alojamientoDetalle')->whereIn('id', $idsHotel)->get();

        $info = [];
        foreach ($hoteles as $hotel) {
            $fotos = collect($hotel->fotos ?? []);
            $fachada = $fotos->firstWhere('tipo_foto', 'fachada');
            $habitaciones = $fotos->where('tipo_foto', 'habitacion')->take(2);

            $tira = collect([$fachada])->filter()->concat($habitaciones)
                ->map(fn (array $f) => $this->imagenRecorte->recortar4x3ParaPdf($f['path']))
                ->filter()
                ->values();

            if ($tira->isEmpty()) {
                continue;
            }

            $detalle = $hotel->proveedor?->alojamientoDetalle;

            $info[$hotel->id] = [
                'fotos' => $tira->all(),
                'check_in' => $detalle?->hora_checkin,
                'check_out' => $detalle?->hora_checkout,
            ];
        }

        return $info;
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
