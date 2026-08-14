<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\Reserva;
use App\Models\Client\Client;
use App\Services\AgenciaViajes\PriceEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Header de cotización — plan-modulo-cotizaciones-reservas.md §3.1. El
// código ({prefijo}-{año}-{correlativo}) lo genera el propio modelo
// Cotizacion (evento creating(), con lockForUpdate() real solo si el
// caller envuelve en transacción — por eso store() abre una).
class CotizacionController extends Controller
{
    public function __construct(private PriceEngineService $priceEngine)
    {
    }

    public function index(Request $request)
    {
        $query = Cotizacion::with('cliente')->withCount('alternativas');

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'ilike', "%{$search}%")
                    ->orWhere('destino', 'ilike', "%{$search}%");
            });
        }

        // Cotizacion no tiene columna 'estado' propia (vive en cada
        // alternativa) — ?estado= filtra cotizaciones que tengan AL MENOS
        // una alternativa en ese estado, no un estado propio del header.
        if ($request->filled('estado')) {
            $estado = $request->get('estado');
            $query->whereHas('alternativas', fn ($q) => $q->where('estado', $estado));
        }

        $cotizaciones = $query->orderByDesc('id')->paginate(15);

        return response()->json([
            'total' => $cotizaciones->total(),
            'paginate' => 15,
            'cotizaciones' => $cotizaciones->items(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cliente_id' => 'required|integer|exists:clients,id',
            'codigo_prefijo' => 'required|string|max:50',
            'destino' => 'required|string|max:250',
            'fecha_viaje_desde' => 'nullable|date',
            'fecha_viaje_hasta' => 'nullable|date|after_or_equal:fecha_viaje_desde',
            'pasajeros' => 'required|array|min:1',
            'pasajeros.*.edad' => 'required|integer|min:0|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        [$edadMaxInfante, $edadMaxNino] = $this->umbralesEdad();

        $cotizacion = DB::transaction(function () use ($validado, $edadMaxInfante, $edadMaxNino) {
            $cotizacion = Cotizacion::create([
                'cliente_id' => $validado['cliente_id'],
                'codigo_prefijo' => $validado['codigo_prefijo'],
                'destino' => $validado['destino'],
                'fecha_viaje_desde' => $validado['fecha_viaje_desde'] ?? null,
                'fecha_viaje_hasta' => $validado['fecha_viaje_hasta'] ?? null,
            ]);

            foreach ($validado['pasajeros'] as $pax) {
                CotizacionPasajero::create([
                    'cotizacion_id' => $cotizacion->id,
                    'edad' => $pax['edad'],
                    'tipo_pax' => $this->derivarTipoPax((int) $pax['edad'], $edadMaxInfante, $edadMaxNino),
                ]);
            }

            return $cotizacion;
        });

        $cotizacion->load('pasajeros', 'cliente');

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
            'alternativas.items.opcionMayorista',
            // Fix guia-como-item-real — nombre del guía/destino para
            // etiquetaItem() de un ítem origen_tipo=guia.
            'alternativas.items.guiaTarifa.guia',
            'alternativas.items.guiaTarifa.destino',
            'alternativas.items.cotizacionPasajeAereo',
            // Sesión 11b3 — nombre del tour de origen para el encabezado del
            // bloque agrupado en el lienzo día-por-día (§7.1).
            'alternativas.items.tourOrigen',
        ])->findOrFail($id);

        return response()->json(['cotizacion' => $cotizacion]);
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

        $validator = Validator::make($request->all(), [
            'cliente_id' => 'required|integer|exists:clients,id',
            'destino' => 'required|string|max:250',
            'fecha_viaje_desde' => 'nullable|date',
            'fecha_viaje_hasta' => 'nullable|date|after_or_equal:fecha_viaje_desde',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $cotizacion->update($validator->validated());
        $cotizacion->load('cliente');

        return response()->json([
            'code' => 200,
            'message' => 'Cotización actualizada correctamente',
            'cotizacion' => $cotizacion,
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
    // precio_venta_snapshot al crear el ítem (contarPasajerosPorTipo()) —
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

        $validator = Validator::make($request->all(), [
            'pasajeros' => 'required|array|min:1',
            'pasajeros.*.id' => 'nullable|integer|exists:cotizacion_pasajeros,id',
            'pasajeros.*.edad' => 'required|integer|min:0|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
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
                        ->update(['edad' => $pax['edad'], 'tipo_pax' => $tipoPax]);
                } else {
                    CotizacionPasajero::create([
                        'cotizacion_id' => $cotizacion->id,
                        'edad' => $pax['edad'],
                        'tipo_pax' => $tipoPax,
                    ]);
                }
            }

            if (! empty($idsEliminados)) {
                $this->limpiarPaxIncluidosEliminados($cotizacion, $idsEliminados);
                CotizacionPasajero::where('cotizacion_id', $cotizacion->id)->whereIn('id', $idsEliminados)->delete();
            }

            // El tipo (adulto/niño/infante) de un pasajero que se queda
            // también puede cambiar (ej. corregir su edad) sin que cambie
            // la cantidad total — igual afecta el reparto por tipo de
            // cualquier ítem 'por_persona' con pax_incluidos=null (todos),
            // así que se recalcula siempre, no solo cuando cambia el total.
            return $this->recalcularItemsPorPersona($cotizacion->fresh('alternativas'));
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

    // Saca los ids eliminados de cualquier pax_incluidos que los tuviera
    // asignados puntualmente (ej. cama adicional para un niño específico).
    // No decide sola si eso deja el ítem "roto" — eso lo evalúa
    // recalcularItemsPorPersona() para los 'por_persona'; un 'tarifa_fija'
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
                    $item->update(['pax_incluidos' => $paxLimpio ?: null]);
                }
            });
    }

    // Recorre las alternativas 'borrador'/'enviada' de la cotización y
    // repreciar cada ítem 'por_persona' contra la composición ACTUAL de
    // pasajeros. Devuelve [cantidad_recalculada, items_para_revisar].
    private function recalcularItemsPorPersona(Cotizacion $cotizacion): array
    {
        $itemsRecalculados = 0;
        $itemsParaRevisar = [];

        foreach ($cotizacion->alternativas as $alternativa) {
            if (! in_array($alternativa->estado, ['borrador', 'enviada'], true)) {
                continue;
            }

            $huboCambio = false;

            foreach ($alternativa->items()->where('modo_precio', 'por_persona')->get() as $item) {
                // Único caso de alta confianza: origen_tipo=proveedor con
                // una proveedor_tarifa real detrás — mismo cálculo exacto
                // que AlternativaItemController::crearItemProveedor().
                if ($item->origen_tipo !== AlternativaItem::ORIGEN_PROVEEDOR || ! $item->proveedor_tarifa_id) {
                    $itemsParaRevisar[] = [
                        'alternativa_item_id' => $item->id,
                        'alternativa_id' => $alternativa->id,
                        'alternativa_nombre' => $alternativa->nombre,
                        'motivo' => $item->origen_tipo === AlternativaItem::ORIGEN_PROVEEDOR
                            ? 'Ítem "por persona" sin proveedor_tarifa real detrás — no se puede repreciar automáticamente.'
                            : 'Ítem "por persona" de origen ' . $item->origen_tipo . ' — se debe repreciar manualmente.',
                    ];
                    continue;
                }

                $tarifa = $item->proveedorTarifa;
                if (! $tarifa) {
                    $itemsParaRevisar[] = [
                        'alternativa_item_id' => $item->id,
                        'alternativa_id' => $alternativa->id,
                        'alternativa_nombre' => $alternativa->nombre,
                        'motivo' => 'La tarifa de proveedor original de este ítem ya no existe — no se puede repreciar automáticamente.',
                    ];
                    continue;
                }

                if (is_array($item->pax_incluidos) && count($item->pax_incluidos) === 0) {
                    $itemsParaRevisar[] = [
                        'alternativa_item_id' => $item->id,
                        'alternativa_id' => $alternativa->id,
                        'alternativa_nombre' => $alternativa->nombre,
                        'motivo' => 'Todos los pasajeros asignados a este ítem fueron eliminados — revisar a quién aplica ahora.',
                    ];
                    continue;
                }

                $conteo = $this->contarPasajerosPorTipo($alternativa->cotizacion_id, $item->pax_incluidos);
                $nuevoPrecioVentaSnapshot = round(
                    $conteo['adulto'] * (float) $tarifa->precio_venta_adulto
                        + $conteo['nino'] * (float) ($tarifa->precio_venta_nino ?? 0)
                        + $conteo['infante'] * (float) ($tarifa->precio_venta_infante ?? 0),
                    2
                );

                $precioListaConvertido = $this->priceEngine->convertirMoneda(
                    $nuevoPrecioVentaSnapshot,
                    $item->moneda_costo,
                    $alternativa->moneda_cotizacion,
                    (float) $alternativa->tipo_cambio_aplicado
                );

                // Preserva cualquier descuento manual ya aplicado sobre el
                // ítem (AlternativaItemController::update()) — recalcular
                // el precio de lista no debe borrar en silencio una
                // negociación ya hecha con el cliente.
                $descuentoPct = (float) ($item->descuento_pct ?? 0);
                $nuevoPrecioConvertido = round($precioListaConvertido * (1 - $descuentoPct / 100), 2);

                // precio_venta_snapshot es decimal:2 (Eloquent lo entrega
                // como string) — comparar como float con round(2) en ambos
                // lados evita falsos "cambió" por ruido de precisión
                // binaria, y evita reescribir el ítem/recalcular el total
                // de la alternativa cuando el precio en realidad no varió.
                if (round((float) $item->precio_venta_snapshot, 2) !== $nuevoPrecioVentaSnapshot) {
                    $item->update([
                        'precio_venta_snapshot' => $nuevoPrecioVentaSnapshot,
                        'precio_convertido' => $nuevoPrecioConvertido,
                    ]);
                    $itemsRecalculados++;
                    $huboCambio = true;
                }
            }

            if ($huboCambio) {
                $total = $alternativa->items()->get()->sum(fn (AlternativaItem $item) => $item->total_convertido);
                $alternativa->update(['total' => round($total, 2)]);
            }
        }

        return [$itemsRecalculados, $itemsParaRevisar];
    }

    private function contarPasajerosPorTipo(int $cotizacionId, ?array $paxIncluidosIds): array
    {
        $query = CotizacionPasajero::where('cotizacion_id', $cotizacionId);
        if ($paxIncluidosIds) {
            $query->whereIn('id', $paxIncluidosIds);
        }
        $pasajeros = $query->get();

        return [
            'adulto' => $pasajeros->where('tipo_pax', 'adulto')->count(),
            'nino' => $pasajeros->where('tipo_pax', 'nino')->count(),
            'infante' => $pasajeros->where('tipo_pax', 'infante')->count(),
        ];
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
        if ($edad <= $edadMaxInfante) {
            return 'infante';
        }
        if ($edad <= $edadMaxNino) {
            return 'nino';
        }

        return 'adulto';
    }

    private function umbralesEdad(): array
    {
        $config = ConfiguracionAgencia::first();

        return [
            $config->edad_max_infante ?? 2,
            $config->edad_max_nino ?? 12,
        ];
    }
}
