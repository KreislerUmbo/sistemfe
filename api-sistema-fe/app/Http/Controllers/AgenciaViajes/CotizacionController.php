<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\Reserva;
use App\Models\Client\Client;
use App\Services\AgenciaViajes\AlternativaCreacionException;
use App\Services\AgenciaViajes\CotizacionCreacionService;
use App\Services\AgenciaViajes\RecalculoPorPersonaService;
use App\Services\AgenciaViajes\SeguimientoCotizacion;
use App\Services\AgenciaViajes\TourLineaService;
use App\Services\HoraPeru;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

// Header de cotización — plan-modulo-cotizaciones-reservas.md §3.1. El
// código ({prefijo}-{año}-{correlativo}) lo genera el propio modelo
// Cotizacion (evento creating(), con lockForUpdate() real solo si el
// caller envuelve en transacción — por eso store() abre una).
class CotizacionController extends Controller
{
    use ValidaEnEspanol;

    // T-033 (R7) — nombre legible de cada campo en los mensajes de validación.
    private const ATRIBUTOS = [
        'cliente_id' => 'cliente',
        'destino' => 'destino',
        'fecha_viaje_desde' => 'fecha de inicio del viaje',
        'fecha_viaje_hasta' => 'fecha de fin del viaje',
        'pasajeros' => 'pasajeros',
        'pasajeros.*.id' => 'pasajero',
        'pasajeros.*.edad' => 'edad del pasajero',
        'pasajeros.*.residencia' => 'residencia del pasajero',
        'alternativa_nombre' => 'nombre de la propuesta',
        'moneda_cotizacion' => 'moneda',
        'tipo_cambio_valor' => 'tipo de cambio',
        'tipo_cambio_confirmado' => 'confirmación del tipo de cambio',
        'origen_contacto_id' => 'origen del contacto',
    ];

    public function __construct(
        private RecalculoPorPersonaService $recalculoPorPersona,
        private TourLineaService $tourLinea,
        private CotizacionCreacionService $cotizacionCreacion,
        private SeguimientoCotizacion $seguimiento,
    ) {
    }

    public function index(Request $request)
    {
        // 'alternativas.reserva' — necesario para Cotizacion::estadoResumen()
        // (09-sep-2026, taxonomía de estados del listado: reservada/anulada/
        // vencida/enviada/borrador/descartada). Sin este eager-load,
        // estadoResumen() dispararía un lazy-load por fila (N+1).
        // Fase 1b (§3.3) — scope de fila: propias() es un scope LOCAL (no
        // global, ver EscopablePorVendedor), así que solo se aplica acá
        // donde corresponde (el listado), nunca en show()/acciones internas.
        // delCotizador() — la cotización de soporte de una venta directa no
        // es una cotización comercial (ver Cotizacion::$origen).
        // T-059 — origenContacto: columna «Origen» del listado.
        // T-053 — resultadoMotivo: «Perdida: Precio» en la columna «Seguimiento».
        $query = Cotizacion::propias()->delCotizador()->with(array_merge(['cliente', 'origenContacto:id,nombre', 'resultadoMotivo:id,nombre'],Cotizacion::RELACIONES_ESTADO_RESUMEN))->withCount('alternativas');

        if ($request->filled('origen_contacto_id')) {
            $query->where('origen_contacto_id', (int) $request->get('origen_contacto_id'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                // También busca por cliente (mismo criterio que
                // ReservaController::index()) — antes solo código/destino,
                // así que encontrar una cotización por el nombre o
                // documento del cliente exigía primero saber su código.
                $q->where('codigo', 'ilike', "%{$search}%")
                    ->orWhere('destino', 'ilike', "%{$search}%")
                    ->orWhereHas('cliente', function ($qq) use ($search) {
                        $qq->where('full_name', 'ilike', "%{$search}%")
                            ->orWhere('n_document', 'ilike', "%{$search}%");
                    });
            });
        }

        // Cotizacion no tiene columna 'estado' propia (vive en cada
        // alternativa) — ?estado= filtra cotizaciones que tengan AL MENOS
        // una alternativa en ese estado literal de Alternativa
        // ('borrador'/'enviada'/'aceptada'/'descartada'), DISTINTO del
        // estado_resumen calculado que devuelve cada fila más abajo (ese es
        // un resumen de negocio — ej. 'reservada'/'anulada' — no un valor
        // real de ninguna columna, no se puede filtrar igual con whereHas).
        if ($request->filled('estado')) {
            $estado = $request->get('estado');
            $query->whereHas('alternativas', fn ($q) => $q->where('estado', $estado));
        }

        if ($request->filled('fecha_desde')) {
            $fechaDesde = $request->get('fecha_desde');
            $query->where(function ($q) use ($fechaDesde) {
                $q->where('fecha_viaje_hasta', '>=', $fechaDesde)
                    ->orWhere('fecha_viaje_desde', '>=', $fechaDesde);
            });
        }

        if ($request->filled('fecha_hasta')) {
            $fechaHasta = $request->get('fecha_hasta');
            $query->where('fecha_viaje_desde', '<=', $fechaHasta);
        }

        // 'estado_resumen' (taxonomía del listado, distinta de 'estado'
        // arriba) es un valor CALCULADO por Cotizacion::estadoResumen() — no
        // existe como columna filtrable en SQL. En vez de reimplementar esa
        // regla de negocio (aceptada+reserva, enviada+vencimiento, etc.) en
        // una segunda versión SQL que se puede desincronizar de la real,
        // se resuelve en dos pasadas: primero se calcula sobre las filas que
        // ya cumplen el resto de filtros (reusando el mismo método), y se
        // acota la query final a esos ids antes de paginar. Costo aceptable
        // para el volumen actual por tenant (decenas/cientos de filas).
        if ($request->filled('estado_resumen')) {
            $estadoResumen = $request->get('estado_resumen');
            $idsCoincidentes = (clone $query)->get()
                ->filter(fn (Cotizacion $c) => $c->estadoResumen() === $estadoResumen)
                ->pluck('id');
            $query->whereIn('id', $idsCoincidentes);
        }

        // ?per_page= es opcional (default 15, igual que antes) — acotado
        // entre 1 y 100 para que un valor arbitrario del cliente no fuerce
        // un paginate() gigante.
        $perPage = min(100, max(1, (int) $request->get('per_page', 15)));

        // T-053 — «Por seguir hoy»: en SQL para que pagine bien; su orden (lo más atrasado
        // primero) va antes del id desc.
        $hoy = CarbonImmutable::parse(HoraPeru::hoyTexto());
        $diasAviso = $this->seguimiento->diasAviso();
        if ($request->boolean('por_seguir')) {
            $this->seguimiento->aplicarFiltroPorSeguir($query, $hoy->toDateString(), $diasAviso);
        }

        $cotizaciones = $query->orderByDesc('id')->paginate($perPage);

        $cotizaciones->getCollection()->transform(function (Cotizacion $c) {
            $c->estado_resumen = $c->estadoResumen();
            // T-074 — qué alternativa se reservó y con qué código, con las relaciones ya
            // precargadas (sin consultas extra por fila).
            $c->alternativa_reservada = $c->alternativaReservada();
            // T-053 (CA4) — tiene_grupos_sin_resolver carga los ítems de cada alternativa (una
            // consulta por alternativa) y el listado no lo usa.
            $c->alternativas->each->setAppends([]);

            return $c;
        });
        // T-053 — columna «Seguimiento», con una sola consulta de acciones para toda la página.
        $this->seguimiento->paraPagina($cotizaciones->getCollection(), $hoy, $diasAviso);

        return response()->json([
            'total' => $cotizaciones->total(),
            'paginate' => $perPage,
            'cotizaciones' => $cotizaciones->items(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = $this->validadorEs($request->all(), [
            'cliente_id' => 'required|integer|exists:clients,id',
            'destino' => 'required|string|max:250',
            'fecha_viaje_desde' => 'nullable|date',
            'fecha_viaje_hasta' => 'nullable|date|after_or_equal:fecha_viaje_desde',
            'pasajeros' => 'required|array|min:1',
            'pasajeros.*.edad' => 'required|integer|min:0|max:120',
            // Fila 13, fase 1b (§3.3): vacío = nacional.
            'pasajeros.*.residencia' => 'nullable|in:nacional,extranjero',
            // T-033 — la primera alternativa nace con la cotización. Vacíos = "Alternativa A" y la
            // moneda por defecto de la agencia. El tipo de cambio es el del día; tipo_cambio_valor
            // solo cuando no hay ninguno registrado ni de SUNAT (la pantalla lo pide tras el 422).
            'alternativa_nombre' => 'nullable|string|max:100',
            'moneda_cotizacion' => 'nullable|in:PEN,USD',
            'tipo_cambio_valor' => 'nullable|numeric|min:0',
            'tipo_cambio_confirmado' => 'nullable|boolean',
            // T-059 — opcional; solo un origen activo.
            'origen_contacto_id' => ['nullable', 'integer', Rule::exists('crm_origenes_contacto', 'id')->where('activo', true)],
        ], self::ATRIBUTOS);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $usuarioId = $request->user()?->id ?? auth()->id();

        try {
            // T-059 — el mismo camino que «Nueva consulta» (CotizacionCreacionService).
            $cotizacion = $this->cotizacionCreacion->crear($validator->validated(), $usuarioId);
        } catch (AlternativaCreacionException $e) {
            // La transacción ya deshizo la cotización: nada a medias.
            return response()->json(['code' => 422, 'message' => $e->getMessage()] + $e->extra, 422);
        }

        $cotizacion->load('pasajeros', 'cliente', 'alternativas');

        return response()->json([
            'code' => 200,
            'message' => 'Cotización creada correctamente',
            'cotizacion' => $cotizacion,
        ]);
    }

    public function show(string $id)
    {
        $cotizacion = Cotizacion::with([
            'cliente',
            'pasajeros',
            // .proveedorServicio.proveedor/.destinoServicio.servicio: sin esto, el
            // frontend (etiquetaItem() en editar.vue) no tiene forma de mostrar el
            // nombre del proveedor ni la categoría del servicio de cada ítem — solo
            // llegaba el proveedor_tarifa "pelado" (sin su cadena de relaciones).
            // .destinoServicio.destinoAtractivo agregado junto con
            // descripcionDestinoServicio() en editar.vue — faltaba acá igual que
            // faltó en PaquetePlantillaItemController::index() (mismo hallazgo).
            'alternativas.items.proveedorTarifa.proveedorServicio.proveedor',
            'alternativas.items.proveedorTarifa.proveedorServicio.destinoServicio.servicio',
            'alternativas.items.proveedorTarifa.proveedorServicio.destinoServicio.destinoAtractivo',
            // .proveedor (04-sep-2026): sin esto, etiquetaItem() del cotizador
            // no tiene forma de mostrar a qué mayorista pertenece un ítem
            // "tarifa fija" (paquete completo sin habitación propia) ni el
            // lienzo puede mostrar el nombre del mayorista en la cabecera de
            // un grupo de hoteles — caía siempre al fallback genérico
            // "Paquete mayorista".
            'alternativas.items.opcionMayorista.proveedor',
            // Pedido del usuario (05-sep-2026): el itinerario incluido
            // (OpcionMayoristaTour, con su "Día") solo se cargaba en la
            // pestaña "Tours" del drawer (GET opciones-mayorista/{id}/tours,
            // perezoso) — el lienzo, que se ve siempre sin abrir el drawer,
            // no tenía forma de mostrarlo. Mismo criterio que .proveedor de
            // arriba: sin esto, el vendedor solo veía el itinerario
            // incluido si entraba a buscarlo a propósito.
            'alternativas.items.opcionMayorista.tours.paquetePlantilla',
            // Sesión M2/M4 — hotel de la matriz (mayorista o ad-hoc LOCAL,
            // ver OpcionHotelController::store()), sin ProveedorTarifa
            // real. Gap real encontrado en verificación en vivo de M4: sin
            // esto, etiquetaItem() del cotizador caía a su fallback
            // genérico "Servicio" para cualquier ítem enganchado por
            // opcion_hotel_tarifa_id — ya estaba en el eager-load del PDF
            // (AlternativaController::pdf()) pero no en este endpoint, que
            // es el que carga la pantalla del cotizador en sí.
            'alternativas.items.opcionHotelTarifa.opcionHotel',
            // 07-sep-2026 — mismo criterio que la línea de arriba, para un
            // ítem que materializa un OpcionMayoristaOpcional elegido (San
            // Blas, Taboga, Colón...): sin esto, etiquetaItem() del
            // cotizador caería al fallback genérico "Paquete mayorista" en
            // vez de mostrar el nombre real del opcional.
            'alternativas.items.opcionMayoristaOpcional',
            // Fix guia-como-item-real — nombre del guía/destino para
            // etiquetaItem() de un ítem origen_tipo=guia.
            'alternativas.items.guiaTarifa.guia',
            'alternativas.items.guiaTarifa.destino',
            'alternativas.items.cotizacionPasajeAereo',
            // Sesión 11b3 — nombre del tour de origen para el encabezado del
            // bloque agrupado en el lienzo día-por-día (§7.1).
            'alternativas.items.tourOrigen',
            // Auditoría del módulo Reservas/Cotizador (2026-08-27) — el
            // frontend necesita saber SIN intentar la acción si un ítem ya
            // generó una reserva (bloquea editar/eliminar). Antes el único
            // aviso era el 422 recién al intentar guardar.
            'alternativas.items.reservaItem',
            // Sesión 12f-1 — el frontend todavía no lee esto (llega en
            // 12f-2), pero tiene que estar disponible para esa sesión sin
            // volver a tocar este endpoint.
            'alternativas.destinos.destinoAtractivo',
            // T-074 — la pestaña de la alternativa reservada (o reemplazada) enlaza a su reserva.
            ...Cotizacion::RELACIONES_ESTADO_RESUMEN,
            // T-052 — el aviso «Perdida: <motivo> · <fecha> (<quién>)» de la cabecera.
            'resultadoMotivo:id,nombre,tipo',
            'perdidaPor:id,name,surname',
        ])->findOrFail($id);

        // Fase 1b (§3.3, advertencia 1) — segunda barrera explícita,
        // independiente del Global Scope (EscopablePorVendedor).
        $this->authorize('view', $cotizacion);

        // T-007: una cotización de modificación dice a qué reserva pertenece (enlace de vuelta y
        // panel de diferencia). Solo se lee; la cotización no cambia.
        $modificacion = null;
        if ($cotizacion->esModificacion()) {
            $m = \App\Models\AgenciaViajes\ReservaModificacion::with('reserva')->where('cotizacion_id', $cotizacion->id)->first();
            $modificacion = $m ? [
                'id' => $m->id,
                'estado' => $m->estado,
                'reserva_id' => $m->reserva_id,
                'reserva_codigo' => $m->reserva?->codigo,
            ] : null;
        }

        return response()->json(['cotizacion' => $cotizacion, 'modificacion' => $modificacion]);
    }

    // Corregir cliente/destino/fechas después de crear la cotización —
    // gap real señalado por el usuario (store()/actualizarPasajeros() eran
    // los únicos puntos de escritura del header hasta ahora, ninguno
    // tocaba estos 4 campos). Sin guard de estado: cliente_id/destino/
    // fechas son solo informativos para este vertical (no alimentan
    // ninguna regla de precio/impuesto de alternativa_items), así que
    // corregirlos no rompe nada aunque la cotización ya tenga
    // alternativas o incluso una reserva aceptada — mismo criterio de
    // simplicidad que actualizarPasajeros(), que tampoco bloquea por estado.
    public function update(Request $request, string $id)
    {
        $cotizacion = Cotizacion::findOrFail($id);

        // Fase 1b (§3.3, advertencia 1) — segunda barrera explícita.
        $this->authorize('update', $cotizacion);

        $validator = $this->validadorEs($request->all(), [
            'cliente_id' => 'required|integer|exists:clients,id',
            'destino' => 'required|string|max:250',
            'fecha_viaje_desde' => 'nullable|date',
            'fecha_viaje_hasta' => 'nullable|date|after_or_equal:fecha_viaje_desde',
        ], self::ATRIBUTOS);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $fechaAntes = $cotizacion->fecha_viaje_desde?->toDateString();
        $cotizacion->update($validator->validated());
        $cotizacion->load('cliente');

        // Fila 13, fase 1b (§3.12): si cambió la fecha y algún tour de una
        // alternativa en borrador quedó en otra temporada, solo se avisa
        // ("Recalcular precios por temporada" lo hace el vendedor a mano).
        $temporadasDesactualizadas = [];
        if ($fechaAntes !== $cotizacion->fecha_viaje_desde?->toDateString()) {
            foreach ($cotizacion->alternativas()->whereIn('estado', ['borrador', 'enviada'])->get() as $alternativa) {
                foreach ($this->tourLinea->temporadasDesactualizadas($alternativa) as $aviso) {
                    $temporadasDesactualizadas[] = $aviso + ['alternativa_id' => $alternativa->id, 'alternativa_nombre' => $alternativa->nombre];
                }
            }
        }

        return response()->json([
            'code' => 200,
            'message' => 'Cotización actualizada correctamente',
            'cotizacion' => $cotizacion,
            'temporadas_desactualizadas' => $temporadasDesactualizadas,
        ]);
    }

    // Gap real señalado por el usuario (14-ago-2026): este endpoint existía
    // desde Sesión 7a pero nunca se conectó a ninguna pantalla — no había
    // forma de aumentar/quitar pasajeros de una cotización ya creada. Al
    // conectarlo se encontraron dos problemas reales, cerrados acá:
    //
    // 1) Este método borraba TODOS los cotizacion_pasajeros y los volvía a
    // crear con ids nuevos en cada guardado, aunque solo se corrigiera una
    // edad. alternativa_items.pax_incluidos (array plano, sin FK real —
    // solo validado en el momento de escribirse) quedaba con ids
    // colgando de pasajeros que ya no existían, en silencio. Ahora es
    // diff por id: solo se crea lo nuevo y se borra lo que de verdad se
    // quitó — un pasajero cuya edad no cambió ni se toca.
    //
    // 2) modo_precio='por_persona' congela el precio en
    // precio_venta_snapshot al crear el ítem (contarPasajerosPorTipo() de RecalculoPorPersonaService) —
    // nada lo recalculaba después. Si la cantidad/composición de
    // pasajeros cambia, esos ítems quedarían cobrando de más o de menos
    // para siempre, sin ningún aviso. Se recalcula automáticamente SOLO
    // para el caso de alta confianza (origen_tipo=proveedor con una
    // proveedor_tarifa real — mismo cálculo exacto que
    // AlternativaItemController::crearItemProveedor()) y únicamente en
    // alternativas 'borrador'/'enviada'. El resto (pasaje_aereo, mayorista,
    // guía, o un ítem sin tarifa real detrás) no se adivina — se informa
    // en items_para_revisar para que el vendedor lo reprecie a mano, mismo
    // principio de "nunca fallback silencioso" que ya rige el resto del
    // proyecto.
    public function actualizarPasajeros(Request $request, string $id)
    {
        $cotizacion = Cotizacion::with('alternativas')->findOrFail($id);
        // T-025 (H2): mismo criterio que update().
        $this->authorize('update', $cotizacion);

        $validator = $this->validadorEs($request->all(), [
            'pasajeros' => 'required|array|min:1',
            'pasajeros.*.id' => 'nullable|integer|exists:cotizacion_pasajeros,id',
            'pasajeros.*.edad' => 'required|integer|min:0|max:120',
            // Fila 13, fase 1b (§3.3): vacío = se conserva (o nacional si es nuevo).
            'pasajeros.*.residencia' => 'nullable|in:nacional,extranjero',
        ], self::ATRIBUTOS);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        // Una alternativa 'aceptada' ya generó una reserva real —
        // ReservaController::crearReservaDesdeAlternativa() copió los
        // cotizacion_pasajeros de ese momento a reserva_pasajeros. Tocar la
        // cotización después de eso no cambia la reserva, solo la
        // desincroniza en silencio. Se bloquea entero (no solo el
        // recálculo) para no dejar la cotización mostrando pasajeros que
        // la reserva real ya no tiene.
        if ($cotizacion->alternativas->contains(fn (Alternativa $a) => $a->estado === 'aceptada')) {
            return response()->json([
                'code' => 422,
                'message' => 'Esta cotización ya tiene una alternativa aceptada (con reserva generada) — los pasajeros no se pueden modificar desde acá.',
            ], 422);
        }

        [$edadMaxInfante, $edadMaxNino] = $this->umbralesEdad();
        $pasajerosPayload = $validator->validated()['pasajeros'];

        // Ids que pertenecen a esta cotización ANTES de tocar nada — para
        // distinguir "pasajero eliminado" (tenía id, ya no viene en el
        // payload) de "pasajero nuevo" (sin id).
        $idsExistentes = $cotizacion->pasajeros()->pluck('id')->all();
        $idsEnPayload = collect($pasajerosPayload)->pluck('id')->filter()->map(fn ($v) => (int) $v)->all();
        $idsEliminados = array_values(array_diff($idsExistentes, $idsEnPayload));

        // T-075: un pasaje aéreo no se queda sin pasajeros ([] se lee como "todos").
        if ($mensaje = $this->pasajesQueQuedanSinPasajeros($cotizacion, $idsExistentes, $idsEliminados)) {
            return response()->json(['code' => 422, 'message' => $mensaje], 422);
        }

        [$itemsRecalculados, $itemsParaRevisar] = DB::transaction(function () use (
            $cotizacion,
            $pasajerosPayload,
            $edadMaxInfante,
            $edadMaxNino,
            $idsEliminados
        ) {
            foreach ($pasajerosPayload as $pax) {
                $tipoPax = $this->derivarTipoPax((int) $pax['edad'], $edadMaxInfante, $edadMaxNino);

                if (! empty($pax['id'])) {
                    CotizacionPasajero::where('cotizacion_id', $cotizacion->id)
                        ->findOrFail($pax['id'])
                        ->update(['edad' => $pax['edad'], 'tipo_pax' => $tipoPax]
                            + (isset($pax['residencia']) ? ['residencia' => $pax['residencia']] : []));
                } else {
                    CotizacionPasajero::create([
                        'cotizacion_id' => $cotizacion->id,
                        'edad' => $pax['edad'],
                        'tipo_pax' => $tipoPax,
                        'residencia' => $pax['residencia'] ?? CotizacionPasajero::RESIDENCIA_NACIONAL,
                    ]);
                }
            }

            if (! empty($idsEliminados)) {
                $this->limpiarPaxIncluidosEliminados($cotizacion, $idsEliminados);
                CotizacionPasajero::where('cotizacion_id', $cotizacion->id)->whereIn('id', $idsEliminados)->delete();
            }

            // T-003: quien confirmó un servicio "para revisar" lo hizo con otros pasajeros.
            AlternativaItem::limpiarRevisadoDeCotizacion($cotizacion->id);

            // El tipo (adulto/niño/infante) de un pasajero que se queda
            // también puede cambiar (ej. corregir su edad) sin que cambie
            // la cantidad total — igual afecta el reparto por tipo de
            // cualquier ítem 'por_persona' con pax_incluidos=null (todos),
            // así que se recalcula siempre, no solo cuando cambia el total.
            return $this->recalculoPorPersona->recalcular($cotizacion->fresh('alternativas'));
        });

        $cotizacion->load('pasajeros', 'alternativas');

        return response()->json([
            'code' => 200,
            'message' => 'Pasajeros actualizados correctamente',
            'cotizacion' => $cotizacion,
            'items_recalculados' => $itemsRecalculados,
            'items_para_revisar' => $itemsParaRevisar,
        ]);
    }

    // T-075 (decisión del usuario, 31-oct): no se borra al último pasajero de un pasaje aéreo.
    // limpiarPaxIncluidosEliminados() lo dejaría con [], que varios servicios leen como "todos":
    // el boleto pasaría a cubrir a todos con el precio que tenía. Devuelve el mensaje para el
    // vendedor, o null si se puede guardar. Las alternativas descartadas o reemplazadas no cuentan.
    private function pasajesQueQuedanSinPasajeros(Cotizacion $cotizacion, array $idsExistentes, array $idsEliminados): ?string
    {
        if ($idsEliminados === []) {
            return null;
        }

        $pasajes = AlternativaItem::with(['alternativa', 'cotizacionPasajeAereo'])
            ->whereHas('alternativa', fn ($q) => $q->where('cotizacion_id', $cotizacion->id)
                ->whereNotIn('estado', ['descartada', 'reemplazada']))
            ->where('origen_tipo', AlternativaItem::ORIGEN_PASAJE_AEREO)
            ->whereNotNull('pax_incluidos')
            ->orderBy('id')
            ->get()
            ->filter(fn (AlternativaItem $item) => $item->pax_incluidos !== []
                && array_diff($item->pax_incluidos, $idsEliminados) === []);

        if ($pasajes->isEmpty()) {
            return null;
        }

        // Los pasajeros de la cotización no tienen nombre: se nombran por su lugar en la lista.
        sort($idsExistentes);
        $tipos = CotizacionPasajero::whereIn('id', $idsEliminados)->pluck('tipo_pax', 'id');
        $nombrePasajero = fn ($id) => 'pasajero '.(array_search((int) $id, $idsExistentes, true) + 1)
            .' ('.($tipos[$id] ?? 'pasajero').')';

        $detalle = $pasajes->map(fn (AlternativaItem $item) => sprintf(
            'el pasaje %s de «%s» es de %s',
            $item->cotizacionPasajeAereo?->aerolinea ?? 'aéreo',
            $item->alternativa->nombre,
            collect($item->pax_incluidos)->map($nombrePasajero)->implode(' y ')
        ))->implode('; ');

        return "No se puede quitar a ese pasajero: {$detalle}. Primero pasa el boleto a otro pasajero o quita el pasaje.";
    }

    // Saca los ids eliminados de cualquier pax_incluidos que los tuviera
    // asignados puntualmente (ej. cama adicional para un niño específico).
    // No decide sola si eso deja el ítem "roto" — eso lo evalúa
    // RecalculoPorPersonaService::recalcular() para los 'por_persona'; un 'tarifa_fija'
    // con pax_incluidos vacío después de esto queda tal cual, visible en
    // el desglose de pasajeros del frontend, sin bloquear nada (mismo
    // criterio no-bloqueante que el resto del cotizador).
    private function limpiarPaxIncluidosEliminados(Cotizacion $cotizacion, array $idsEliminados): void
    {
        AlternativaItem::whereHas('alternativa', fn ($q) => $q->where('cotizacion_id', $cotizacion->id))
            ->whereNotNull('pax_incluidos')
            ->get()
            ->each(function (AlternativaItem $item) use ($idsEliminados) {
                $paxIncluidos = $item->pax_incluidos ?? [];
                $paxLimpio = array_values(array_diff($paxIncluidos, $idsEliminados));

                if ($paxLimpio !== $paxIncluidos) {
                    $item->update(['pax_incluidos' => $paxLimpio]);
                }
            });
    }

    // Borra el header completo (cliente + pasajeros) junto con TODAS sus
    // alternativas — hasta esta sesión, cada Alternativa individual se
    // podía borrar (AlternativaController::destroy()) pero el header de la
    // Cotizacion quedaba huérfano para siempre, sin forma de limpiar una
    // cotización de prueba o una donde el cliente nunca avanzó. Mismo
    // guard que AlternativaController::destroy() (una reserva ya generada
    // no se puede perder), extendido a CUALQUIERA de las alternativas de
    // la cotización. Reusa AlternativaController::eliminarCascada() por
    // alternativa (misma cascada real, no reescrita acá) dentro de una
    // sola transacción que también borra los pasajeros y el header.
    public function destroy(string $id)
    {
        $cotizacion = Cotizacion::with('alternativas')->findOrFail($id);

        // Fase 1b (§3.3, advertencia 1) — segunda barrera explícita.
        $this->authorize('delete', $cotizacion);

        $conReserva = Reserva::whereIn('alternativa_id', $cotizacion->alternativas->pluck('id'))->exists();
        if ($conReserva) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: esta cotización ya generó una reserva.',
            ], 422);
        }

        DB::transaction(function () use ($cotizacion) {
            foreach ($cotizacion->alternativas as $alternativa) {
                AlternativaController::eliminarCascada($alternativa);
            }
            CotizacionPasajero::where('cotizacion_id', $cotizacion->id)->delete();
            $cotizacion->delete();
        });

        return response()->json(['code' => 200, 'message' => 'Cotización eliminada correctamente']);
    }

    private function derivarTipoPax(int $edad, int $edadMaxInfante, int $edadMaxNino): string
    {
        return RecalculoPorPersonaService::derivarTipoPax($edad, $edadMaxInfante, $edadMaxNino);
    }

    private function umbralesEdad(): array
    {
        return RecalculoPorPersonaService::umbralesEdad();
    }
}
