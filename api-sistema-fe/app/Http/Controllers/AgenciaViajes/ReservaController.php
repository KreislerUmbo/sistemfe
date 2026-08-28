<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaAnticipo;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SalidaMayorista;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Services\AgenciaViajes\CodigoGeneradorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

// De alternativa aceptada a Reserva — plan-modulo-cotizaciones-reservas.md
// §4. aceptar() reemplaza el "TODO Sesión 11c" que dejó
// AlternativaController::update() (Sesión 11b) — reusa
// AlternativaController::descartarOtras() en vez de duplicar esa lógica.
//
// REGLA FIJA (Fase 1 del fix Cotización↔Reserva, 2026-08-18): la fecha de
// una reserva se lee siempre de reserva.fecha_viaje_desde/hasta — NUNCA de
// reserva.alternativa.cotizacion.fecha_viaje_desde/hasta. La relación
// 'alternativa.cotizacion' de RELACIONES_DETALLE sigue cargada abajo (se
// necesita para cliente/destino/código) y su fecha_viaje_desde/hasta SÍ
// viaja en el JSON de show() como parte de esa relación completa — eso es
// esperado, es la propuesta comercial vigente, no el compromiso operativo
// de esta reserva. Quien necesite la fecha de la reserva usa el campo
// directo de Reserva o el bloque 'cabecera' de respuestaDetalle(), nunca
// ese camino anidado. Ver docblock de Reserva::class.
class ReservaController extends Controller
{
    private const RELACIONES_DETALLE = [
        'alternativa.cotizacion.cliente',
        'pasajeros.pasajeroCatalogo',
        'items.alternativaItem.proveedorTarifa.proveedorServicio.proveedor',
        'items.alternativaItem.proveedorTarifa.proveedorServicio.destinoServicio.servicio',
        'items.alternativaItem.proveedorTarifa.proveedorServicio.destinoServicio.destinoAtractivo',
        'items.alternativaItem.opcionMayorista.proveedor',
        'items.alternativaItem.cotizacionPasajeAereo',
        'items.guia',
        'items.proveedorTarifa.proveedorServicio.proveedor',
        'items.pasajeros',
        'items.vueloPasajeros',
        'items.salidaOperativa.guia',
        'items.tourOrigen',
        'anticipos.advance.sale',
    ];

    public function index(Request $request)
    {
        $query = Reserva::with('alternativa.cotizacion.cliente');

        if ($request->filled('estado')) {
            $query->where('estado', $request->get('estado'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($outer) use ($search) {
                // Módulo 12: reserva ya tiene su propio código (RDKM-...),
                // pero reservas creadas antes del módulo siguen sin uno —
                // busca en ambos, nunca solo en el de la cotización padre.
                $outer->where('codigo', 'ilike', "%{$search}%")
                    ->orWhereHas('alternativa.cotizacion', function ($q) use ($search) {
                        $q->where('codigo', 'ilike', "%{$search}%")
                            ->orWhere('destino', 'ilike', "%{$search}%")
                            ->orWhereHas('cliente', function ($qq) use ($search) {
                                $qq->where('full_name', 'ilike', "%{$search}%")
                                    ->orWhere('n_document', 'ilike', "%{$search}%");
                            });
                    });
            });
        }

        $reservas = $query->orderByDesc('id')->paginate(15);

        return response()->json([
            'total' => $reservas->total(),
            'paginate' => 15,
            'reservas' => $reservas->items(),
        ]);
    }

    public function show(string $id)
    {
        $reserva = Reserva::with(self::RELACIONES_DETALLE)->findOrFail($id);

        return response()->json($this->respuestaDetalle($reserva));
    }

    // POST alternativas/{id}/aceptar — punto 1 del prompt. Reusa
    // AlternativaController::descartarOtras() y crearReservaDesdeAlternativa()
    // (esta última también la usa VentaDirectaController::store(), §4.1).
    public function aceptar(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);

        if (! in_array($alternativa->estado, ['borrador', 'enviada'], true)) {
            return response()->json([
                'code' => 422,
                'message' => 'La alternativa no está en un estado válido para aceptar (ya fue aceptada o descartada).',
            ], 422);
        }

        $existeReservaActiva = Reserva::whereHas(
            'alternativa',
            fn ($q) => $q->where('cotizacion_id', $alternativa->cotizacion_id)
        )->where('estado', 'activa')->exists();

        if ($existeReservaActiva) {
            return response()->json([
                'code' => 422,
                'message' => 'Esta cotización ya tiene una reserva activa.',
            ], 422);
        }

        // Opcional: el vendedor ya sabe quién es cada pasajero al aceptar —
        // lista alineada por orden (id asc) con cotizacion_pasajeros, cada
        // posición nullable.
        $pasajeroCatalogoIds = $request->input('pasajero_catalogo_ids', []);

        [$reserva, $alertaCupoExcedido] = DB::transaction(function () use ($alternativa, $pasajeroCatalogoIds) {
            $alternativa->update(['estado' => 'aceptada']);
            AlternativaController::descartarOtras($alternativa);

            return $this->crearReservaDesdeAlternativa($alternativa->fresh(), $pasajeroCatalogoIds);
        });

        $reserva->load(self::RELACIONES_DETALLE);

        return response()->json(array_merge(
            ['code' => 200, 'message' => 'Reserva creada correctamente', 'alerta_cupo_excedido' => $alertaCupoExcedido],
            $this->respuestaDetalle($reserva)
        ));
    }

    public function cancelar(Request $request, string $id)
    {
        $reserva = Reserva::findOrFail($id);

        if ($reserva->estado === 'cancelada') {
            return response()->json(['code' => 422, 'message' => 'La reserva ya está cancelada.'], 422);
        }

        // Sin esto, se podía cancelar una reserva que ya generó una venta
        // real facturada (SUNAT) sin ningún aviso — reserva_ventas es la
        // tabla puente hacia Sale, ver ReservaVenta.
        if (ReservaVenta::where('reserva_id', $reserva->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede cancelar: esta reserva ya tiene una venta/comprobante asociado.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'motivo_cancelacion' => 'required|in:voluntaria,fuerza_mayor,clima,falta_pago_cuotas',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $motivo = $validator->validated()['motivo_cancelacion'];

        DB::transaction(function () use ($reserva, $motivo) {
            $reserva->update([
                'estado' => 'cancelada',
                'fecha_cancelacion' => now(),
                'motivo_cancelacion' => $motivo,
            ]);

            // Liberación de cupo (§4.2, entra en el primer lanzamiento aunque
            // el resto de 4.2 sea Fase 2) — mismo movimiento que el
            // incremento al aceptar, en reversa.
            $alternativa = $reserva->alternativa;
            $opcionElegida = $alternativa?->opcionMayoristaElegida;

            if ($opcionElegida && $opcionElegida->salida_mayorista_id) {
                $totalPax = $reserva->pasajeros()->count();
                SalidaMayorista::where('id', $opcionElegida->salida_mayorista_id)->decrement('cupo_ocupado', $totalPax);
            }
        });

        return response()->json(['code' => 200, 'message' => 'Reserva cancelada correctamente', 'reserva' => $reserva->fresh()]);
    }

    // Compartido con VentaDirectaController::store() — arma
    // reserva/reserva_pasajeros/reserva_items desde una alternativa YA
    // marcada 'aceptada' (el caller decide cuándo/cómo llegó a ese estado).
    // No abre su propia transacción — el caller ya está dentro de una.
    public function crearReservaDesdeAlternativa(Alternativa $alternativa, array $pasajeroCatalogoIds = []): array
    {
        $opcionElegida = $alternativa->opcionMayoristaElegida;

        // Fase 1 del fix Cotización↔Reserva (2026-08-18): fecha_viaje_desde/
        // hasta se copian de la cotización UNA SOLA VEZ, acá — de ahí en
        // adelante esta reserva ya no depende de que nadie vuelva a editar
        // (o no) la cotización. Ver docblock de Reserva::class.
        // Módulo 12 (plan-modulo-codigos-numeracion.md §4.2): reserva no
        // tiene numeración propia, deriva el código de la cotización padre
        // (prefijo R + resto del código de la cotización, sufijo si es la
        // 2da+ reserva de esa misma cotización).
        $codigo = app(CodigoGeneradorService::class)->generarParaReserva($alternativa->cotizacion);

        $reserva = Reserva::create([
            'codigo' => $codigo,
            'alternativa_id' => $alternativa->id,
            'mayorista_elegida_id' => $opcionElegida?->id,
            'estado_reserva_mayorista' => $opcionElegida ? 'pendiente' : null,
            'estado' => 'activa',
            'fecha_viaje_desde' => $alternativa->cotizacion->fecha_viaje_desde,
            'fecha_viaje_hasta' => $alternativa->cotizacion->fecha_viaje_hasta,
        ]);

        $cotizacionPasajeros = CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)
            ->orderBy('id')
            ->get();

        // mapaPasajeros: cotizacion_pasajero_id → reserva_pasajero_id, para
        // poder propagar pax_incluidos (alternativa_items) a
        // reserva_item_pasajero más abajo — Sesión 11q, esa tabla existía
        // desde Sesión 8a pero nunca se llenaba.
        $mapaPasajeros = [];

        foreach ($cotizacionPasajeros as $index => $cotizacionPasajero) {
            $reservaPasajero = ReservaPasajero::create([
                'reserva_id' => $reserva->id,
                'tipo_pax' => $cotizacionPasajero->tipo_pax,
                'pasajero_catalogo_id' => $pasajeroCatalogoIds[$index] ?? null,
            ]);
            $mapaPasajeros[$cotizacionPasajero->id] = $reservaPasajero->id;
        }

        $alternativaItems = AlternativaItem::where('alternativa_id', $alternativa->id)->get();

        // Base del cálculo = la propia reserva recién creada (ya congelada),
        // NUNCA la cotización en vivo — ver comentario arriba.
        $fechaViajeDesde = $reserva->fecha_viaje_desde;

        foreach ($alternativaItems as $alternativaItem) {
            $this->crearReservaItemDesdeAlternativaItem($reserva, $alternativaItem, $fechaViajeDesde, $mapaPasajeros);
        }

        $alertaCupoExcedido = false;

        if ($opcionElegida && $opcionElegida->salida_mayorista_id) {
            $totalPax = $cotizacionPasajeros->count();
            SalidaMayorista::where('id', $opcionElegida->salida_mayorista_id)->increment('cupo_ocupado', $totalPax);

            $salida = SalidaMayorista::find($opcionElegida->salida_mayorista_id);
            if ($salida && $salida->cupo_total !== null && $salida->cupo_ocupado > $salida->cupo_total) {
                $alertaCupoExcedido = true;
            }
        }

        return [$reserva, $alertaCupoExcedido];
    }

    // Extraído de crearReservaDesdeAlternativa() para reusar en
    // sincronizarItems() (agregar un servicio a la cotización DESPUÉS de que
    // la reserva ya está activa nunca se refleja solo — el staff dispara la
    // sincronización a mano, ver sincronizarItems()).
    private function crearReservaItemDesdeAlternativaItem(
        Reserva $reserva,
        AlternativaItem $alternativaItem,
        $fechaViajeDesde,
        array $mapaPasajeros
    ): ReservaItem {
        $fechaCalculada = ($fechaViajeDesde && $alternativaItem->dia_referencial)
            ? $fechaViajeDesde->copy()->addDays($alternativaItem->dia_referencial - 1)
            : null;

        $reservaItem = ReservaItem::create([
            'reserva_id' => $reserva->id,
            'alternativa_item_id' => $alternativaItem->id,
            'proveedor_tarifa_id' => $alternativaItem->proveedor_tarifa_id,
            'fecha' => $fechaCalculada,
            // Fase 1 del fix Cotización↔Reserva — este método es el único
            // punto de creación de reserva_items (aceptar y sincronizar),
            // así que todo lo que nace acá es 'auto' por definición. Un
            // ítem solo pasa a 'manual' después, vía
            // ReservaItemController::update().
            'fecha_origen' => ReservaItem::FECHA_ORIGEN_AUTO,
            // Sesión 11b4: propaga de qué tour vino el ítem (si vino de
            // explotar un paquete_combo) para que la agrupación visual
            // "Día 1/Día 2" sobreviva también en la reserva.
            'tour_origen_id' => $alternativaItem->tour_origen_id,
        ]);

        $this->engancharSalidaOperativa($reservaItem, $alternativaItem, $fechaCalculada);

        // pax_incluidos null/vacío = aplica a todos, no se crea ninguna
        // fila (mismo criterio que pax_incluidos en alternativa_items).
        if (! empty($alternativaItem->pax_incluidos)) {
            foreach ($alternativaItem->pax_incluidos as $cotizacionPasajeroId) {
                if (isset($mapaPasajeros[$cotizacionPasajeroId])) {
                    ReservaItemPasajero::create([
                        'reserva_item_id' => $reservaItem->id,
                        'reserva_pasajero_id' => $mapaPasajeros[$cotizacionPasajeroId],
                    ]);
                }
            }
        }

        return $reservaItem;
    }

    // Enganche automático a una salida_operativa compartida — ver diseño
    // completo en la migración de creación de salidas_operativas. Solo
    // origen_tipo=proveedor con modalidad=compartido se engancha solo:
    // proveedor_tarifas.modalidad SÍ tiene semántica compartido/privado
    // real. guia_tarifas.modalidad ('dia_local'/'grupo_multidia') es un eje
    // de duración de contrato, sin ninguna señal confiable de si un ítem
    // de guía puntual es exclusivo de una reserva o compartible con
    // otras — así que los ítems origen_tipo=guia NUNCA se auto-enganchan
    // en esta fase, quedan disponibles para engancharse a mano desde el
    // tablero (SalidaOperativaController::attachReservaItem()). Ítems sin
    // tour_origen_id tampoco se enganchan nunca (mismo motivo: sin esa
    // clave no hay forma de agrupar).
    private function engancharSalidaOperativa(ReservaItem $reservaItem, AlternativaItem $alternativaItem, $fechaCalculada): void
    {
        if (! $fechaCalculada || ! $alternativaItem->tour_origen_id) {
            return;
        }

        if ($alternativaItem->origen_tipo !== AlternativaItem::ORIGEN_PROVEEDOR) {
            return;
        }

        if ($alternativaItem->proveedorTarifa?->modalidad !== 'compartido') {
            return;
        }

        try {
            $salida = SalidaOperativa::firstOrCreate(
                ['tour_origen_id' => $alternativaItem->tour_origen_id, 'fecha' => $fechaCalculada],
                ['estado' => 'activa']
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // Condición de carrera: otra request creó la misma salida entre
            // el find y el create de firstOrCreate() — el índice único
            // parcial (ver migración) la bloqueó. Recupera la que ya existe
            // en vez de tragarte el error.
            $salida = SalidaOperativa::where('tour_origen_id', $alternativaItem->tour_origen_id)
                ->where('fecha', $fechaCalculada)
                ->first();

            if (! $salida) {
                throw $e;
            }
        }

        $reservaItem->update(['salida_operativa_id' => $salida->id]);
    }

    // Reconstruye cotizacion_pasajero_id → reserva_pasajero_id para una
    // reserva YA existente (mismo criterio posicional que
    // crearReservaDesdeAlternativa(): los reserva_pasajeros se crean una
    // sola vez, en el mismo orden que cotizacion_pasajeros, y no se agregan
    // ni se borran después — por eso alinear por orden sigue siendo válido
    // acá).
    private function reconstruirMapaPasajeros(Reserva $reserva): array
    {
        $cotizacionPasajeros = CotizacionPasajero::where('cotizacion_id', $reserva->alternativa->cotizacion_id)
            ->orderBy('id')
            ->get();
        $reservaPasajeros = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get()->values();

        $mapa = [];
        foreach ($cotizacionPasajeros as $index => $cp) {
            if (isset($reservaPasajeros[$index])) {
                $mapa[$cp->id] = $reservaPasajeros[$index]->id;
            }
        }

        return $mapa;
    }

    // POST reservas/{id}/sincronizar-items — Opción C acordada: nunca
    // automático. Crea los reserva_items que falten para alternativa_items
    // agregados a la cotización DESPUÉS de que la reserva ya estaba activa.
    public function sincronizarItems(string $id)
    {
        $reserva = Reserva::findOrFail($id);

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede sincronizar una reserva activa.'], 422);
        }

        $alternativaItemIdsEnReserva = ReservaItem::where('reserva_id', $reserva->id)->pluck('alternativa_item_id')->all();

        $itemsPendientes = AlternativaItem::where('alternativa_id', $reserva->alternativa_id)
            ->whereNotIn('id', $alternativaItemIdsEnReserva)
            ->get();

        if ($itemsPendientes->isEmpty()) {
            return response()->json(['code' => 422, 'message' => 'No hay ítems pendientes de sincronizar.'], 422);
        }

        // Fase 1 del fix Cotización↔Reserva: base propia de la reserva, NO
        // la cotización en vivo — así los ítems que se sincronizan ahora
        // calculan contra la misma fecha base que los ítems que ya existían
        // desde que se aceptó la alternativa, sin importar si la cotización
        // cambió de fecha en el medio. Sigue significando exactamente lo
        // mismo que antes ("incorporar a la reserva los ítems nuevos de la
        // alternativa"), nunca "recalcular toda la reserva" — los
        // reserva_items que ya existían no se tocan acá.
        $fechaViajeDesde = $reserva->fecha_viaje_desde;
        $mapaPasajeros = $this->reconstruirMapaPasajeros($reserva);

        DB::transaction(function () use ($itemsPendientes, $reserva, $fechaViajeDesde, $mapaPasajeros) {
            foreach ($itemsPendientes as $alternativaItem) {
                $this->crearReservaItemDesdeAlternativaItem($reserva, $alternativaItem, $fechaViajeDesde, $mapaPasajeros);
            }
        });

        $cantidad = $itemsPendientes->count();
        $reserva->load(self::RELACIONES_DETALLE);

        return response()->json(array_merge(
            ['code' => 200, 'message' => "{$cantidad} ítem(s) sincronizado(s) correctamente."],
            $this->respuestaDetalle($reserva)
        ));
    }

    // POST reservas/{id}/reprogramar — Fase 2 del fix Cotización↔Reserva
    // (2026-08-19). Evento de negocio explícito, distinto de una simple
    // corrección de campo: mueve fecha_viaje_desde/hasta de una reserva YA
    // aceptada y recalcula reserva_items.fecha SOLO para los ítems
    // fecha_origen='auto' (ver ReservaItem::FECHA_ORIGEN_*), preservando
    // intactos los editados a mano. Nunca toca cotizacion.fecha_viaje_desde/
    // hasta — la cotización sigue siendo el registro de la propuesta
    // original/vigente, sin propagación automática (fuera de alcance,
    // decisión explícita del brief).
    public function reprogramar(Request $request, string $id)
    {
        $reserva = Reserva::findOrFail($id);

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede reprogramar una reserva activa.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'fecha_viaje_desde' => 'required|date',
            'fecha_viaje_hasta' => 'nullable|date|after_or_equal:fecha_viaje_desde',
            'motivo' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $itemsNoTocados = DB::transaction(function () use ($reserva, $validado) {
            // Conserva el estado ANTERIOR a esta reprogramación (auditoría
            // simple, no historial completo — mismo trade-off ya aceptado
            // por fecha_cancelacion/motivo_cancelacion, ver docblock de
            // Reserva::class). Si ya hubo una reprogramación previa, esto
            // pisa el "_original" con la fecha tal como quedó tras la
            // última, no la fecha real de creación.
            $reserva->update([
                'fecha_viaje_desde_original' => $reserva->fecha_viaje_desde,
                'fecha_viaje_hasta_original' => $reserva->fecha_viaje_hasta,
                'fecha_viaje_desde' => $validado['fecha_viaje_desde'],
                'fecha_viaje_hasta' => $validado['fecha_viaje_hasta'] ?? null,
                'fecha_reprogramacion' => now(),
                'motivo_reprogramacion' => $validado['motivo'],
            ]);
            $reserva->refresh();

            $itemsNoTocados = [];

            $items = ReservaItem::with('alternativaItem')->where('reserva_id', $reserva->id)->get();

            foreach ($items as $item) {
                if ($item->fecha_origen === ReservaItem::FECHA_ORIGEN_MANUAL) {
                    $itemsNoTocados[] = [
                        'reserva_item_id' => $item->id,
                        'nombre' => self::resolverNombreItem($item->alternativaItem),
                        'fecha' => $item->fecha?->toDateString(),
                        'motivo' => 'manual',
                    ];
                    continue;
                }

                $diaReferencial = $item->alternativaItem?->dia_referencial;
                if ($diaReferencial === null) {
                    // Mismo criterio que crearReservaItemDesdeAlternativaItem():
                    // sin dia_referencial no hay insumo para recalcular. No
                    // es 'manual', pero tampoco hay nada que mover.
                    //
                    // 2026-08-20: antes se saltaba en silencio (sin listar
                    // en items_no_tocados) — riesgo real de que el ítem
                    // quedara con la fecha VIEJA después de "reprogramar
                    // correctamente" sin ninguna señal en la respuesta.
                    // Ahora se lista igual que los 'manual', con motivo
                    // propio, para que el vendedor sepa que este servicio
                    // necesita revisión manual de fecha.
                    $itemsNoTocados[] = [
                        'reserva_item_id' => $item->id,
                        'nombre' => self::resolverNombreItem($item->alternativaItem),
                        'fecha' => $item->fecha?->toDateString(),
                        'motivo' => 'sin_dia_referencial',
                    ];
                    continue;
                }

                $fechaNueva = $reserva->fecha_viaje_desde->copy()->addDays($diaReferencial - 1);

                if ($item->fecha && $item->fecha->toDateString() === $fechaNueva->toDateString()) {
                    continue; // sin cambio real, no reenganchar nada de más
                }

                $item->update(['fecha' => $fechaNueva]);

                // Re-enganche a SalidaOperativa: agrupa por
                // (tour_origen_id, fecha) — un ítem que cambió de fecha ya
                // no pertenece a la salida vieja. Se desengancha primero
                // (la SalidaOperativa vieja NUNCA se borra acá, puede
                // seguir compartida por otras reservas) y
                // engancharSalidaOperativa() decide si corresponde una
                // nueva (o crearla), mismas reglas que al aceptar.
                //
                // NO se toca SalidaMayorista.cupo_ocupado acá — confirmado
                // leyendo crearReservaDesdeAlternativa()/cancelar() antes
                // de escribir esto (no asumido del brief): ese contador es
                // por RESERVA completa (reserva.mayorista_elegida_id,
                // fijado una única vez al aceptar/cancelar, atado a una
                // salida de catálogo con fecha propia), nunca por
                // reserva_item — no existe ningún camino donde recalcular
                // reserva_items.fecha deba mover ese cupo.
                if ($item->salida_operativa_id) {
                    $item->update(['salida_operativa_id' => null]);
                }
                $this->engancharSalidaOperativa($item, $item->alternativaItem, $fechaNueva);
            }

            return $itemsNoTocados;
        });

        $reserva->load(self::RELACIONES_DETALLE);

        return response()->json(array_merge(
            [
                'code' => 200,
                'message' => 'Reserva reprogramada correctamente',
                'items_no_tocados' => $itemsNoTocados,
            ],
            $this->respuestaDetalle($reserva)
        ));
    }

    // PUT reservas/{id}/facturacion-externa — override por reserva,
    // independiente de tenants.facturacion_habilitada. Editable SOLO
    // mientras la reserva no tenga ninguna fila en reserva_ventas (ver
    // Reserva::ventas()) — 422 si ya tiene un Sale, reversible libremente
    // hasta ese momento (PEGAR-EN-CLAUDE-CODE-facturacion-externa-tenant.md
    // §3.2).
    public function actualizarFacturacionExterna(Request $request, string $id)
    {
        $reserva = Reserva::findOrFail($id);

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede editar facturación externa de una reserva activa.'], 422);
        }

        if ($reserva->ventas()->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede editar facturación externa: esta reserva ya tiene una venta/comprobante asociado en la plataforma.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'facturacion_externa' => 'required|boolean',
            'referencia_externa' => 'nullable|string|max:255',
            'fecha_facturacion_externa' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $facturacionExterna = $validado['facturacion_externa'];

        // Transacción + lock: cierra la ventana de carrera con
        // ReservaFacturacionController::store() sobre la misma reserva — el
        // check de "sin venta asociada" de arriba corrió antes del lock, así
        // que sin esto un POST facturar concurrente podría colarse entre ese
        // check y este update.
        DB::transaction(function () use ($reserva, $facturacionExterna, $validado) {
            $reservaLocked = Reserva::where('id', $reserva->id)->lockForUpdate()->firstOrFail();

            if ($reservaLocked->ventas()->exists()) {
                throw new HttpException(
                    422,
                    'No se puede editar facturación externa: esta reserva ya tiene una venta/comprobante asociado en la plataforma.'
                );
            }

            // Al desmarcar, se limpian referencia/fecha también — es
            // anotación de estado actual, no un historial; "se equivocó,
            // cambió de opinión" debe dejar la reserva en un estado limpio,
            // no con datos viejos colgando.
            $reservaLocked->update([
                'facturacion_externa' => $facturacionExterna,
                'referencia_externa' => $facturacionExterna ? ($validado['referencia_externa'] ?? null) : null,
                'fecha_facturacion_externa' => $facturacionExterna ? ($validado['fecha_facturacion_externa'] ?? null) : null,
            ]);
        });

        return response()->json(['code' => 200, 'message' => 'Actualizado correctamente', 'reserva' => $reserva->fresh()]);
    }

    // "resumen" para el panel de precio en vivo (§7.1 del prototipo) — el
    // frontend no recalcula nada, solo pinta nombre + total_convertido +
    // el TOTAL ya cerrado.
    private function respuestaDetalle(Reserva $reserva): array
    {
        $resumen = $reserva->items->map(function (ReservaItem $item) {
            return [
                'reserva_item_id' => $item->id,
                'nombre' => self::resolverNombreItem($item->alternativaItem),
                // Sesión facturación-de-reservas, punto 2 del review de
                // proceso: sin esto, dos ítems del mismo servicio en días
                // distintos (ej. "Entrada / Ticket de ingreso" el día 1 y
                // el día 2) se veían como filas idénticas en el panel de
                // resumen, sin ninguna forma de distinguirlas.
                'fecha' => $item->fecha?->toDateString(),
                'precio_venta_snapshot' => $item->alternativaItem->precio_venta_snapshot,
                'total_convertido' => $item->alternativaItem->total_convertido,
                // Auditoría de UX del módulo (2026-08-27): el resumen salía
                // en el orden de carga de la relación, sin agrupar por
                // tour/día como ya hace el cotizador (mismo tour_origen_id
                // que ya viaja en el ítem desde Sesión 11b4) — el frontend
                // arma los bloques, esto solo expone el dato.
                'tour_origen_id' => $item->tour_origen_id,
                'tour_origen_nombre' => $item->tourOrigen?->nombre,
            ];
        })->values();

        $total = $reserva->items->sum(fn (ReservaItem $item) => (float) $item->alternativaItem->total_convertido);

        $cotizacion = $reserva->alternativa->cotizacion;

        $alternativaItemIdsEnReserva = $reserva->items->pluck('alternativa_item_id')->all();
        $itemsPendientesSincronizar = AlternativaItem::where('alternativa_id', $reserva->alternativa_id)
            ->whereNotIn('id', $alternativaItemIdsEnReserva)
            ->get()
            ->map(fn (AlternativaItem $i) => ['id' => $i->id, 'nombre' => self::resolverNombreItem($i)])
            ->values();

        // Fase A — facturación de reservas: qué reserva_items ya están
        // cubiertos por alguna ReservaVenta, para que el frontend no
        // vuelva a ofrecerlos al facturar (el backend igual lo bloquea con
        // 422, esto es solo para que la UI no invite a un error evitable).
        $itemsFacturadosIds = $reserva->ventas
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_item_ids ?? [])
            ->unique()
            ->values();

        $itemsPendientesDeFacturarCount = $reserva->items
            ->pluck('id')
            ->diff($itemsFacturadosIds)
            ->count();

        // Facturación múltiple por grupo de pasajeros (2026-08-20): cada
        // pasajero queda "facturado" en cuanto aparece en el
        // reserva_pasajero_ids de ALGUNA ReservaVenta — usado por el
        // frontend para el badge "Facturación completa" vs. "Falta
        // facturar a N pasajeros" sin tener que abrir el modal.
        $pasajerosFacturadosIds = $reserva->ventas
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_pasajero_ids ?? [])
            ->unique()
            ->values();

        // Tier 0 — conexión Adelantos↔Reservas (hallazgo de auditoría del
        // módulo Adelantos, 2026-08-21): anticipos que el cliente ya pagó
        // hacia esta reserva, con el estado SUNAT de su propio comprobante
        // (no el de la venta que eventualmente los consuma).
        $anticipos = $reserva->anticipos->map(function (ReservaAnticipo $ra) {
            return [
                'id' => $ra->id,
                'advance_id' => $ra->advance_id,
                'monto' => (float) $ra->monto_asignado,
                'disponible' => $ra->advance->availableBalance(),
                'moneda' => $ra->advance->currency,
                'fecha_asignacion' => $ra->fecha_asignacion?->toDateString(),
                'comprobante_enviado' => (bool) $ra->advance->sale?->n_operacion,
            ];
        })->values();

        return [
            'reserva' => $reserva,
            'resumen' => $resumen,
            'total' => round($total, 2),
            'moneda' => $reserva->alternativa->moneda_cotizacion,
            'items_pendientes_sincronizar' => $itemsPendientesSincronizar,
            'items_facturados_ids' => $itemsFacturadosIds,
            'items_pendientes_de_facturar_count' => $itemsPendientesDeFacturarCount,
            'pasajeros_facturados_ids' => $pasajerosFacturadosIds,
            'anticipos' => $anticipos,
            'total_anticipos_disponibles' => round($anticipos->sum('disponible'), 2),
            // Facturación externa por tenant (PEGAR-EN-CLAUDE-CODE-
            // facturacion-externa-tenant.md): el frontend necesita el flag
            // del tenant acá para decidir si ofrece "Facturar"/"Facturación
            // especial" sin una segunda llamada — el backend igual bloquea
            // con 403 si se intenta directo (ReservaFacturacionController).
            // facturacion_externa_editable = sin ninguna ReservaVenta
            // todavía ($reserva->ventas ya está resuelto arriba, sin query
            // extra).
            'facturacion_habilitada_tenant' => (bool) tenant('facturacion_habilitada'),
            'facturacion_externa_editable' => $reserva->ventas->isEmpty(),
            'cabecera' => [
                'cliente' => $cotizacion->cliente,
                'destino' => $cotizacion->destino,
                // Fase 1 del fix Cotización↔Reserva: la fecha de la
                // cabecera de una reserva es la de la RESERVA (congelada al
                // aceptar), no la de la cotización en vivo — ver docblock
                // de Reserva::class. cliente/destino SÍ siguen siendo de la
                // cotización, eso no cambió (son puramente informativos,
                // sin ningún cálculo operativo detrás).
                'fecha_viaje_desde' => $reserva->fecha_viaje_desde,
                'fecha_viaje_hasta' => $reserva->fecha_viaje_hasta,
                'codigo_cotizacion' => $cotizacion->codigo,
                // Módulo 12 (códigos y numeración, revisión 26-ago-2026):
                // código propio de la reserva. Fallback al de la cotización
                // para reservas creadas antes de activar el módulo (sin
                // código retroactivo, ver migración de reserva.codigo).
                'codigo' => $reserva->codigo ?? $cotizacion->codigo,
            ],
        ];
    }

    // Mismo criterio que etiquetaItem() en cotizador/editar.vue (Sesión
    // 11b) — replicado acá para que el resumen de la reserva no dependa de
    // que el frontend recalcule con la cadena completa de relaciones.
    // public static (no private): reusado también por
    // ReservaFacturacionController para armar descripcion_detalle de cada
    // línea de venta agrupada.
    public static function resolverNombreItem(AlternativaItem $item): string
    {
        if ($item->origen_tipo === AlternativaItem::ORIGEN_MANUAL) {
            return $item->descripcion_manual ?? 'Ítem manual';
        }
        if ($item->origen_tipo === AlternativaItem::ORIGEN_PASAJE_AEREO) {
            return $item->cotizacionPasajeAereo?->aerolinea ?? 'Pasaje aéreo';
        }
        if ($item->origen_tipo === AlternativaItem::ORIGEN_MAYORISTA) {
            $proveedor = $item->opcionMayorista?->proveedor;

            return ($proveedor?->nombre_comercial ?: $proveedor?->razon_social) ?? 'Paquete mayorista';
        }
        if ($item->proveedorTarifa?->tipo_habitacion) {
            // nombre_comercial antes que razon_social — mismo criterio que ya usa
            // etiquetaItem() en cotizador/editar.vue (frontend); esta función
            // (resolverNombreItem, backend) se había quedado atrás mostrando solo
            // razón social, lo que generaba el mismo proveedor con 2 nombres
            // distintos según la pantalla (rediseño-reporte-operativo).
            $proveedorModel = $item->proveedorTarifa->proveedorServicio?->proveedor;
            $proveedor = ($proveedorModel?->nombre_comercial ?: $proveedorModel?->razon_social) ?? 'Hotel';

            return "{$proveedor} · {$item->proveedorTarifa->tipo_habitacion}";
        }

        return $item->proveedorTarifa?->proveedorServicio?->destinoServicio?->servicio?->nombre ?? 'Servicio';
    }
}
