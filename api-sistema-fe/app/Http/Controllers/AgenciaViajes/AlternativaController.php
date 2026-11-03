<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Concerns\AutorizaCotizacion;
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
use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Services\AgenciaViajes\AlternativaCopiaService;
use App\Services\AgenciaViajes\AlternativaCreacionException;
use App\Services\AgenciaViajes\AlternativaCreacionService;
use App\Services\AgenciaViajes\AlternativaPdfService;
use App\Services\AgenciaViajes\PriceEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Alternativa de cotización — plan-modulo-cotizaciones-reservas.md §3.1/§3.2.
// Anidada bajo cotización (store), directa por su propio id (update/destroy).
class AlternativaController extends Controller
{
    use AutorizaCotizacion;
    use ValidaEnEspanol;

    private const MAX_ALTERNATIVAS_POR_COTIZACION = AlternativaCreacionService::MAX_ALTERNATIVAS_POR_COTIZACION;

    // T-052: una cotización perdida no recibe alternativas nuevas (enviar y aceptar ya quedan
    // bloqueados porque todas sus alternativas están descartadas).
    private const MENSAJE_COTIZACION_PERDIDA = 'Esta cotización está cerrada como perdida. Reabre la cotización primero.';

    // T-033 (R7) — nombre legible de cada campo en los mensajes de validación.
    private const ATRIBUTOS = [
        'nombre' => 'nombre',
        'moneda_cotizacion' => 'moneda',
        'tipo_cambio_origen' => 'origen del tipo de cambio',
        'tipo_cambio_valor' => 'tipo de cambio',
        'tipo_cambio_confirmado' => 'confirmación del tipo de cambio',
        'estado' => 'estado',
        'descuento_global_pct' => 'descuento global (%)',
        'descuento_global_monto' => 'descuento global',
    ];

    public function __construct(
        private PriceEngineService $priceEngine,
        private AlternativaPdfService $alternativaPdfService,
        private AlternativaCopiaService $alternativaCopia,
        private AlternativaCreacionService $alternativaCreacion,
    ) {
    }

    public function store(Request $request, string $cotizacionId)
    {
        $cotizacion = Cotizacion::findOrFail($cotizacionId);
        $this->autorizarCotizacion('update', $cotizacion);

        if ($cotizacion->estaPerdida()) {
            return response()->json(['code' => 422, 'message' => self::MENSAJE_COTIZACION_PERDIDA], 422);
        }

        $validator = $this->validadorEs($request->all(), [
            // T-033: vacío = "Alternativa {letra}" sugerida, en vez de error.
            'nombre' => 'nullable|string|max:100',
            'moneda_cotizacion' => 'required|in:PEN,USD',
            'tipo_cambio_origen' => 'required|in:dia,agencia',
            // Opcional: si se manda, registra un tipo_cambio_agencia NUEVO con
            // este valor (§3.4, "opción de digitar uno nuevo si no está
            // registrado todavía") en vez de reusar el último ya existente.
            'tipo_cambio_valor' => 'nullable|numeric|min:0',
            // Segundo paso consciente si tipo_cambio_valor cae fuera del
            // rango de sanidad (2.0-6.0 USD/PEN) — ver TipoCambioSanityService.
            'tipo_cambio_confirmado' => 'nullable|boolean',
        ], self::ATRIBUTOS);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $validado = $validator->validated();

        try {
            $alternativa = $this->alternativaCreacion->crear(
                $cotizacion,
                $validado['nombre'] ?? null,
                $validado['moneda_cotizacion'],
                $validado['tipo_cambio_origen'],
                isset($validado['tipo_cambio_valor']) ? (float) $validado['tipo_cambio_valor'] : null,
                (bool) ($validado['tipo_cambio_confirmado'] ?? false),
                $request->user()?->id ?? auth()->id(),
            );
        } catch (AlternativaCreacionException $e) {
            return response()->json(['code' => 422, 'message' => $e->getMessage()] + $e->extra, 422);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Alternativa creada correctamente',
            'alternativa' => $alternativa,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $alternativa = Alternativa::findOrFail($id);
        $this->autorizarCotizacion('update', $alternativa);

        $validator = $this->validadorEs($request->all(), [
            'nombre' => 'nullable|string|max:100',
            // T-033 — cambiar la moneda solo sin servicios (ver más abajo).
            'moneda_cotizacion' => 'nullable|in:PEN,USD',
            'estado' => 'nullable|in:borrador,enviada,aceptada,descartada',
            'descuento_global_pct' => 'nullable|numeric|min:0|max:100',
            // Sesión 11i — alternativa al de arriba cuando configuracion_agencia.
            // modo_descuento_global='monto'. El frontend manda uno u otro,
            // nunca los dos en el mismo request (un solo input visible según
            // el modo configurado) — no se valida la exclusión mutua acá
            // porque no hay forma de que ambos lleguen juntos desde la UI.
            'descuento_global_monto' => 'nullable|numeric|min:0',
        ], self::ATRIBUTOS);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $validado = array_filter($validator->validated(), fn ($v) => $v !== null);
        // 29-ago-2026 — capitalización tipo título solo si vino en el
        // payload (array_filter ya sacó los null, así que isset alcanza).
        if (isset($validado['nombre'])) {
            $validado['nombre'] = \App\Services\TextoFormatoService::capitalizarNombrePropio($validado['nombre']);
        }

        // T-033 — la moneda se cambia solo mientras no haya servicios: con servicios habría que
        // convertir sus precios (fuera de alcance). La misma moneda no es un cambio.
        $cambiaMoneda = isset($validado['moneda_cotizacion']) && $validado['moneda_cotizacion'] !== $alternativa->moneda_cotizacion;
        if (! $cambiaMoneda) {
            unset($validado['moneda_cotizacion']);
        } elseif ($alternativa->items()->exists()) {
            return response()->json([
                'code' => 422,
                'message' => "Esta alternativa ya tiene servicios: su moneda no se puede cambiar. Quita los servicios o crea otra alternativa en {$validado['moneda_cotizacion']}.",
                'errors' => ['moneda_cotizacion' => ["Quita los servicios o crea otra alternativa en {$validado['moneda_cotizacion']}."]],
            ], 422);
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

        // T-030 (H3): una aceptada ya generó su reserva; volverla a borrador,
        // enviada o descartada por este PUT la dejaba fuera del flujo (una
        // reserva activa colgando de una alternativa "no aceptada"). El
        // nombre sí se sigue cambiando.
        if ($alternativa->estado === 'aceptada' && array_key_exists('estado', $validado) && $validado['estado'] !== 'aceptada') {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa ya fue aceptada y generó una reserva — su estado ya no se cambia desde acá.',
            ], 422);
        }

        // T-002: 'reemplazada' la escribe solo "aplicar modificación" (T-005); desde este PUT ni
        // se fija (el `in:` de arriba no lo admite) ni se cambia una que ya lo está.
        if ($alternativa->estado === Alternativa::ESTADO_REEMPLAZADA && array_key_exists('estado', $validado)) {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa fue reemplazada por una modificación de la reserva — su estado ya no se cambia.',
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

        // T-033 — moneda nueva con el tipo de cambio del día (mismo orden que al crear).
        if ($cambiaMoneda) {
            try {
                $tipoCambio = $this->alternativaCreacion->resolverTipoCambio('dia', null, $request->user()?->id ?? auth()->id());
            } catch (AlternativaCreacionException $e) {
                return response()->json(['code' => 422, 'message' => $e->getMessage()] + $e->extra, 422);
            }
            $validado['tipo_cambio_aplicado'] = $tipoCambio->valor;
            $validado['tipo_cambio_origen'] = 'dia';
        }

        DB::transaction(function () use ($alternativa, $validado, &$lineasFueraDePiso) {
            if (($validado['estado'] ?? null) === 'enviada') {
                $validado += $alternativa->camposAlMarcarEnviada();
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
            // Guardrail (19-sep-2026, hallazgo real del usuario) — antes esta
            // closure devolvía el precio de lista UNITARIO (sin cantidad),
            // pero sumaPreciosLista necesita representar el TOTAL de lista
            // de la alternativa (mismo criterio que
            // AlternativaItem::getTotalConvertidoAttribute(): un ítem
            // 'tarifa_fija' multiplica por cantidad — mayorista con 2
            // adultos, o un hotel Local/Nacional con varias noches). Sin
            // esto, un ítem con cantidad>1 hacía que sumaPreciosLista
            // saliera de menos, y pctEfectivo = monto/sumaPreciosLista se
            // inflaba en ese mismo factor — pedir un descuento de 118 sobre
            // un total de 2118 (hotel matrimonial a 1059 × 2 adultos)
            // terminaba descontando 236 (118 × 2) en vez de 118.
            $precioListaConvertidoDe = function (AlternativaItem $item) use ($alternativa) {
                $precioUnitario = $this->priceEngine->convertirMoneda(
                    (float) $item->precio_venta_snapshot,
                    $item->moneda_costo,
                    $alternativa->moneda_cotizacion,
                    (float) $alternativa->tipo_cambio_aplicado
                );

                return $item->modo_precio === 'tarifa_fija' ? $precioUnitario * $item->cantidad : $precioUnitario;
            };

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
        $this->autorizarCotizacion('update', $alternativa);

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
        $this->autorizarCotizacion('update', $original);

        if ($original->cotizacion->estaPerdida()) {
            return response()->json(['code' => 422, 'message' => self::MENSAJE_COTIZACION_PERDIDA], 422);
        }

        if ($original->cotizacion->alternativas()->count() >= self::MAX_ALTERNATIVAS_POR_COTIZACION) {
            return response()->json([
                'code' => 422,
                'message' => 'Esta cotización ya tiene el máximo de ' . self::MAX_ALTERNATIVAS_POR_COTIZACION . ' alternativas permitidas.',
            ], 422);
        }

        // T-002: la copia profunda vive en AlternativaCopiaService (la usa también
        // "Modificar reserva"); acá solo se abre la transacción.
        [$nueva] = DB::transaction(fn () => $this->alternativaCopia->copiar($original, $original->cotizacion_id, $original->nombre . ' (copia)'));

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
        $this->autorizarCotizacion('view', Alternativa::findOrFail($id));

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
}
