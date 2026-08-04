<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajeAereo;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Services\AgenciaViajes\ComboExplosionService;
use App\Services\AgenciaViajes\PriceEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// El corazón de la Sesión 11b — plan-modulo-cotizaciones-reservas.md §3.
// store() se ramifica en 4 según origen_tipo (proveedor/mayorista/
// pasaje_aereo/manual), cada uno con su propia forma de resolver
// costo_snapshot/precio_venta_snapshot. update() es la edición en vivo de
// descuento_pct/precio_convertido con validación de piso.
class AlternativaItemController extends Controller
{
    public function __construct(
        private PriceEngineService $priceEngine,
        private ComboExplosionService $comboExplosion
    ) {
    }

    public function store(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        // input(), NO get() — get() es el ParameterBag de Symfony (query
        // string/route/POST-form), no lee body JSON crudo. Bug real
        // encontrado corriendo la verificación de esta sesión.
        $origenTipo = $request->input('origen_tipo');

        if (! in_array($origenTipo, AlternativaItem::ORIGENES, true)) {
            return response()->json([
                'code' => 422,
                'message' => 'origen_tipo inválido — debe ser uno de: ' . implode(', ', AlternativaItem::ORIGENES),
            ], 422);
        }

        return match ($origenTipo) {
            AlternativaItem::ORIGEN_PROVEEDOR => $this->crearItemProveedor($request, $alternativa),
            AlternativaItem::ORIGEN_MAYORISTA => $this->crearItemMayorista($request, $alternativa),
            AlternativaItem::ORIGEN_PASAJE_AEREO => $this->crearItemPasajeAereo($request, $alternativa),
            AlternativaItem::ORIGEN_MANUAL => $this->crearItemManual($request, $alternativa),
            AlternativaItem::ORIGEN_HOTEL_PLANTILLA => $this->crearItemHotelPlantilla($request, $alternativa),
        };
    }

    // Edición en vivo de descuento_pct O precio_convertido — §3.1. El
    // backend siempre recalcula el que falta y devuelve AMBOS, más
    // alerta_piso/precio_minimo_permitido, para que el frontend nunca
    // tenga que hacer la conversión matemática él mismo.
    public function update(Request $request, string $id)
    {
        $item = AlternativaItem::with(['alternativa', 'proveedorTarifa'])->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'descuento_pct' => 'nullable|numeric|min:0|max:100',
            'precio_convertido' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        if (! isset($validado['descuento_pct']) && ! isset($validado['precio_convertido'])) {
            return response()->json(['code' => 422, 'message' => 'Enviá descuento_pct o precio_convertido.'], 422);
        }

        $alternativa = $item->alternativa;
        $precioListaConvertido = $this->priceEngine->convertirMoneda(
            (float) $item->precio_venta_snapshot,
            $item->moneda_costo,
            $alternativa->moneda_cotizacion,
            (float) $alternativa->tipo_cambio_aplicado
        );

        if (isset($validado['descuento_pct'])) {
            $descuentoPct = $validado['descuento_pct'];
            $precioConvertido = round($precioListaConvertido * (1 - $descuentoPct / 100), 2);
        } else {
            $precioConvertido = $validado['precio_convertido'];
            $descuentoPct = $precioListaConvertido > 0
                ? round((1 - $precioConvertido / $precioListaConvertido) * 100, 2)
                : 0;
        }

        // Piso solo si el ítem viene de una proveedor_tarifa real — ítems
        // manuales y "precio de referencia sin proveedor asignado" (§3) no
        // tienen tarifa de la que derivar descuento_maximo_pct/margen_minimo_pct.
        $alertaPiso = false;
        $precioMinimoPermitido = null;

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

            $precioMinimoPermitido = $pisoInfo['precio_minimo_permitido'];
            $alertaPiso = $pisoInfo['alerta_piso'];
        }

        $item->update([
            'descuento_pct' => $descuentoPct,
            'precio_convertido' => $precioConvertido,
        ]);

        $this->recalcularTotalAlternativa($alternativa);

        return response()->json([
            'code' => 200,
            'message' => 'Ítem actualizado correctamente',
            'alternativa_item' => $item->fresh(),
            'precio_minimo_permitido' => $precioMinimoPermitido,
            'alerta_piso' => $alertaPiso,
        ]);
    }

    public function destroy(string $id)
    {
        $item = AlternativaItem::with('alternativa')->findOrFail($id);
        $alternativa = $item->alternativa;

        DB::transaction(function () use ($item) {
            if ($item->origen_tipo === AlternativaItem::ORIGEN_PASAJE_AEREO) {
                CotizacionPasajeAereo::where('alternativa_item_id', $item->id)->delete();
            }
            $item->delete();
        });

        $this->recalcularTotalAlternativa($alternativa);

        return response()->json(['code' => 200, 'message' => 'Ítem eliminado correctamente']);
    }

    // Sesión 11b3 (Parte A) — "cargar desde plantilla": explota TODOS los
    // ítems de un tour_simple/paquete_combo en la alternativa activa,
    // resueltos en vivo contra su proveedor_tarifa real (nunca copia
    // precio_venta_final del catálogo como un solo número — mismo criterio
    // ya usado por clicBibliotecaItem() para un ítem suelto, en bucle).
    // Reusa ComboExplosionService::explotarTourSimple()/explotarItems() —
    // mismo motor que ya resuelve el precio "calculado" que el vendedor ve
    // en el catálogo (paquetes/detalle.vue), así que el total acá siempre
    // coincide con el que ya vio ahí.
    public function desdePlantilla(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);

        $validator = Validator::make($request->all(), [
            'paquete_plantilla_id' => 'required|integer|exists:paquetes_plantilla,id',
            'dia_referencial' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $paquete = PaquetePlantilla::findOrFail($validado['paquete_plantilla_id']);

        // Cada entrada trae su propio dia_referencial ya resuelto — para un
        // tour_simple suelto, el mismo día activo para todos sus ítems
        // (decisión confirmada: un tour multi-día cae en un solo bloque, no
        // se distribuye ítem por ítem dentro del tour, ver CLAUDE.md). Para
        // un paquete_combo, cada tour-hijo ocupa su PROPIO día de inicio —
        // mismo offset que ComboExplosionService::itinerarioDerivado() ya usa
        // para el itinerario derivado (tour_itinerario_items.dia_relativo
        // máximo de cada tour), no un solo día plano para todo el combo.
        $entradas = [];
        if ($paquete->esPaqueteCombo()) {
            $offsetDia = 0;
            foreach ($this->comboExplosion->toursDelCombo($paquete) as $tourHijo) {
                $diaDelTour = $validado['dia_referencial'] + $offsetDia;

                foreach ($this->comboExplosion->explotarTourSimple($tourHijo) as $entrada) {
                    $entrada['dia_referencial'] = $diaDelTour;
                    $entradas[] = $entrada;
                }

                $offsetDia += (int) ($tourHijo->paqueteItinerario()->max('dia_relativo') ?? 0);
            }
        } else {
            foreach ($this->comboExplosion->explotarTourSimple($paquete) as $entrada) {
                $entrada['dia_referencial'] = $validado['dia_referencial'];
                $entradas[] = $entrada;
            }
        }

        // Sesión 11k — hoteles disponibles del paquete/tour(es) recién
        // cargado(s), devueltos SIN auto-agregarse (mismo criterio
        // no-bloqueante que guiasPendientes de abajo): una matriz de hotel
        // tiene varias tarifas por tipo_habitacion, así que no se puede
        // auto-explotar como un proveedor_tarifa/guia_tarifa — el vendedor
        // tiene que elegir la habitación antes de que el ítem tenga precio.
        $tourIds = $paquete->esPaqueteCombo()
            ? [$paquete->id, ...$this->comboExplosion->toursDelCombo($paquete)->pluck('id')->all()]
            : [$paquete->id];

        $hotelesDisponibles = OpcionHotel::whereIn('paquete_plantilla_id', $tourIds)
            ->with('opcionesHotelTarifas')
            ->get()
            ->map(fn (OpcionHotel $hotel) => [
                'id' => $hotel->id,
                'paquete_plantilla_id' => $hotel->paquete_plantilla_id,
                'nombre_hotel' => $hotel->nombre_hotel,
                'categoria_estrellas' => $hotel->categoria_estrellas,
                'opciones_hotel_tarifas' => $hotel->opcionesHotelTarifas,
            ])
            ->values();

        $itemsCreados = [];
        $guiasPendientes = [];

        DB::transaction(function () use ($alternativa, $entradas, &$itemsCreados, &$guiasPendientes) {
            foreach ($entradas as $entrada) {
                if ($entrada['proveedor_tarifa_id']) {
                    $tarifa = ProveedorTarifa::findOrFail($entrada['proveedor_tarifa_id']);

                    $itemsCreados[] = AlternativaItem::create([
                        'alternativa_id' => $alternativa->id,
                        'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
                        'proveedor_tarifa_id' => $tarifa->id,
                        'tour_origen_id' => $entrada['tour_origen_id'],
                        'dia_referencial' => $entrada['dia_referencial'],
                        // tarifa_fija/cantidad=1: el precio que trae la
                        // explosión ya es el tier adulto plano, sin repartir
                        // por pax — mismo criterio que el precio "calculado"
                        // del catálogo (totalesTour()/totalesCombo()). Usar
                        // por_persona acá multiplicaría de nuevo y el total
                        // dejaría de coincidir con lo que el vendedor vio en
                        // el catálogo antes de cargarlo.
                        'modo_precio' => 'tarifa_fija',
                        'cantidad' => 1,
                        'moneda_costo' => $tarifa->moneda,
                        'costo_snapshot' => $entrada['costo'],
                        'precio_venta_snapshot' => $entrada['venta'],
                        'precio_convertido' => $this->priceEngine->convertirMoneda($entrada['venta'], $tarifa->moneda, $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
                    ]);

                    continue;
                }

                if ($entrada['guia_tarifa_id']) {
                    // Sin equivalente en alternativa_items (no hay columna
                    // guia_tarifa_id) — §3.7: se avisa, no se persiste, no
                    // bloquea el resto de la carga.
                    $guiaTarifa = GuiaTarifa::with(['guia', 'destino'])->find($entrada['guia_tarifa_id']);
                    $tourOrigen = PaquetePlantilla::find($entrada['tour_origen_id']);

                    $guiasPendientes[] = [
                        'tour_origen_id' => $entrada['tour_origen_id'],
                        'tour_origen_nombre' => $tourOrigen?->nombre,
                        'guia_nombre' => $guiaTarifa?->guia?->nombre,
                        'destino_nombre' => $guiaTarifa?->destino?->nombre,
                    ];
                }
            }

            $this->recalcularTotalAlternativa($alternativa);
        });

        return response()->json([
            'code' => 200,
            'message' => 'Plantilla cargada correctamente',
            'items_agregados' => $itemsCreados,
            'guias_pendientes' => $guiasPendientes,
            'hoteles_disponibles' => $hotelesDisponibles,
            'resumen' => [
                'tours' => $paquete->esPaqueteCombo() ? $this->comboExplosion->toursDelCombo($paquete)->count() : null,
                'items' => count($itemsCreados),
            ],
        ]);
    }

    // Sesión 11b3 — reasignar el día de un ítem SUELTO (sin tour_origen_id).
    // Los ítems que pertenecen a un bloque de tour se mueven juntos, ver
    // moverBloque() — separado de update() (piso/descuento) a propósito: es
    // otro concern y no debía arriesgar esa validación ya verificada.
    public function reasignarDia(Request $request, string $id)
    {
        $item = AlternativaItem::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'dia_referencial' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        if ($item->tour_origen_id !== null) {
            return response()->json([
                'code' => 422,
                'message' => 'Este ítem pertenece a un bloque de tour — mové todo el bloque desde su encabezado.',
            ], 422);
        }

        $item->update(['dia_referencial' => $validator->validated()['dia_referencial']]);

        return response()->json(['code' => 200, 'message' => 'Ítem movido correctamente', 'alternativa_item' => $item->fresh()]);
    }

    // Reasigna el día de TODOS los ítems de un mismo tour_origen_id juntos —
    // §7.1, decisión confirmada con el usuario: un bloque de tour se mueve
    // como unidad, no ítem por ítem.
    public function moverBloque(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);

        $validator = Validator::make($request->all(), [
            'tour_origen_id' => 'required|integer',
            'dia_referencial' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $actualizados = AlternativaItem::where('alternativa_id', $alternativa->id)
            ->where('tour_origen_id', $validado['tour_origen_id'])
            ->update(['dia_referencial' => $validado['dia_referencial']]);

        if ($actualizados === 0) {
            return response()->json([
                'code' => 422,
                'message' => 'No se encontraron ítems de ese tour en esta alternativa.',
            ], 422);
        }

        return response()->json(['code' => 200, 'message' => 'Bloque movido correctamente']);
    }

    // ── origen_tipo=proveedor ─────────────────────────────────────────
    private function crearItemProveedor(Request $request, Alternativa $alternativa): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'proveedor_tarifa_id' => 'nullable|integer|exists:proveedor_tarifas,id',
            'modo_precio' => 'required|in:por_persona,tarifa_fija',
            'cantidad' => 'nullable|integer|min:1',
            'pax_incluidos' => 'nullable|array',
            'pax_incluidos.*' => 'integer|exists:cotizacion_pasajeros,id',
            // Solo si NO hay proveedor_tarifa_id — precio de referencia (§3, caso 2)
            'moneda_costo' => 'required_without:proveedor_tarifa_id|nullable|in:PEN,USD',
            'precio_venta_snapshot' => 'required_without:proveedor_tarifa_id|nullable|numeric|min:0',
            'costo_snapshot' => 'nullable|numeric|min:0',
            // Día activo del lienzo al momento de agregar — Sesión 11b3 (§7.1).
            'dia_referencial' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $tarifa = ! empty($validado['proveedor_tarifa_id']) ? ProveedorTarifa::findOrFail($validado['proveedor_tarifa_id']) : null;
        $cantidad = $validado['cantidad'] ?? 1;
        $paxIncluidos = $validado['pax_incluidos'] ?? null;

        if ($tarifa) {
            $monedaCosto = $tarifa->moneda;
            $costoSnapshot = (float) $tarifa->precio_costo;

            if ($validado['modo_precio'] === 'por_persona') {
                $conteo = $this->contarPasajerosPorTipo($alternativa->cotizacion_id, $paxIncluidos);
                $precioVentaSnapshot = $conteo['adulto'] * (float) $tarifa->precio_venta_adulto
                    + $conteo['nino'] * (float) ($tarifa->precio_venta_nino ?? 0)
                    + $conteo['infante'] * (float) ($tarifa->precio_venta_infante ?? 0);
            } else {
                // tarifa_fija (hotel por habitación, transporte privado por vehículo):
                // un solo precio, no diferenciado por tipo de pasajero.
                $precioVentaSnapshot = (float) $tarifa->precio_venta_adulto;
            }
        } else {
            $monedaCosto = $validado['moneda_costo'];
            // costo_snapshot es NOT NULL — sin tarifa real de la que
            // derivarlo, 0 es el sentinel (mismo criterio que items manuales).
            $costoSnapshot = $validado['costo_snapshot'] ?? 0;
            $precioVentaSnapshot = (float) $validado['precio_venta_snapshot'];
        }

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
            'proveedor_tarifa_id' => $tarifa?->id,
            'modo_precio' => $validado['modo_precio'],
            'cantidad' => $cantidad,
            'pax_incluidos' => $paxIncluidos,
            'moneda_costo' => $monedaCosto,
            'costo_snapshot' => $costoSnapshot,
            'precio_venta_snapshot' => $precioVentaSnapshot,
            'precio_convertido' => $this->priceEngine->convertirMoneda($precioVentaSnapshot, $monedaCosto, $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
            'dia_referencial' => $validado['dia_referencial'] ?? null,
        ]);

        $this->recalcularTotalAlternativa($alternativa);

        return response()->json(['code' => 200, 'message' => 'Ítem agregado correctamente', 'alternativa_item' => $item]);
    }

    // ── origen_tipo=mayorista ─────────────────────────────────────────
    private function crearItemMayorista(Request $request, Alternativa $alternativa): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'opcion_mayorista_id' => 'required|integer|exists:opcion_mayorista,id',
            'opcion_hotel_tarifa_id' => 'required|integer|exists:opciones_hotel_tarifas,id',
            'cantidad' => 'nullable|integer|min:1',
            'pax_incluidos' => 'nullable|array',
            'dia_referencial' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $opcion = OpcionMayorista::findOrFail($validado['opcion_mayorista_id']);

        if ($opcion->alternativa_id !== $alternativa->id) {
            return response()->json(['code' => 422, 'message' => 'La opción de mayorista no pertenece a esta alternativa.'], 422);
        }
        if ($opcion->estado !== 'elegida') {
            return response()->json(['code' => 422, 'message' => 'Marcá la opción de mayorista como elegida antes de agregarla al ítem.'], 422);
        }

        $tarifaHotel = OpcionHotelTarifa::with('opcionHotel')->findOrFail($validado['opcion_hotel_tarifa_id']);
        if ($tarifaHotel->opcionHotel?->opcion_mayorista_id !== $opcion->id) {
            return response()->json(['code' => 422, 'message' => 'Esa tarifa de habitación no pertenece a la opción de mayorista indicada.'], 422);
        }

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA,
            'opcion_mayorista_id' => $opcion->id,
            'modo_precio' => 'tarifa_fija', // paquete internacional, siempre por habitación
            'cantidad' => $validado['cantidad'] ?? 1,
            'pax_incluidos' => $validado['pax_incluidos'] ?? null,
            'moneda_costo' => $opcion->moneda,
            'costo_snapshot' => $tarifaHotel->precio_costo,
            'precio_venta_snapshot' => $tarifaHotel->precio_venta,
            'precio_convertido' => $this->priceEngine->convertirMoneda((float) $tarifaHotel->precio_venta, $opcion->moneda, $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
            'dia_referencial' => $validado['dia_referencial'] ?? null,
        ]);

        $this->recalcularTotalAlternativa($alternativa);

        return response()->json(['code' => 200, 'message' => 'Ítem de mayorista agregado correctamente', 'alternativa_item' => $item]);
    }

    // ── origen_tipo=hotel_plantilla (Sesión 11k) ────────────────────────
    // Mismo patrón que crearItemMayorista(): un hotel de paquete_plantilla
    // tiene varias tarifas por tipo_habitacion, así que no se auto-explota
    // al cargar la plantilla (ver desdePlantilla()/hoteles_disponibles) —
    // el vendedor elige la habitación acá, uno por uno.
    private function crearItemHotelPlantilla(Request $request, Alternativa $alternativa): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'paquete_plantilla_id' => 'required|integer|exists:paquetes_plantilla,id',
            'opcion_hotel_tarifa_id' => 'required|integer|exists:opciones_hotel_tarifas,id',
            'cantidad' => 'nullable|integer|min:1',
            'tour_origen_id' => 'nullable|integer',
            'dia_referencial' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $tarifaHotel = OpcionHotelTarifa::with('opcionHotel')->findOrFail($validado['opcion_hotel_tarifa_id']);
        if ((int) $tarifaHotel->opcionHotel?->paquete_plantilla_id !== (int) $validado['paquete_plantilla_id']) {
            return response()->json(['code' => 422, 'message' => 'Esa tarifa de habitación no pertenece al paquete indicado.'], 422);
        }

        $monedaCosto = $tarifaHotel->opcionHotel->moneda;

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'origen_tipo' => AlternativaItem::ORIGEN_HOTEL_PLANTILLA,
            'opcion_hotel_tarifa_id' => $tarifaHotel->id,
            'paquete_plantilla_id' => $validado['paquete_plantilla_id'],
            'tour_origen_id' => $validado['tour_origen_id'] ?? null,
            'modo_precio' => 'tarifa_fija', // matriz de habitación, siempre por habitación
            'cantidad' => $validado['cantidad'] ?? 1,
            'moneda_costo' => $monedaCosto,
            'costo_snapshot' => $tarifaHotel->precio_costo,
            'precio_venta_snapshot' => $tarifaHotel->precio_venta,
            'precio_convertido' => $this->priceEngine->convertirMoneda((float) $tarifaHotel->precio_venta, $monedaCosto, $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
            'dia_referencial' => $validado['dia_referencial'] ?? null,
        ]);

        $this->recalcularTotalAlternativa($alternativa);

        return response()->json(['code' => 200, 'message' => 'Ítem de hotel agregado correctamente', 'alternativa_item' => $item]);
    }

    // Recalculo en vivo del formulario de pasaje aéreo (PasajeAereoForm.vue)
    // — misma validación y misma llamada a PriceEngineService que
    // crearItemPasajeAereo(), pero sin persistir nada. "No reimplementes la
    // suma en el frontend, pedísela al backend" (spec de esta sesión).
    public function previewPasajeAereo(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);

        $validado = $this->validarPasajeAereo($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $resultado = $this->calcularPasajeAereo($alternativa, $validado);

        return response()->json(['code' => 200, 'resultado' => $resultado]);
    }

    // ── origen_tipo=pasaje_aereo ────────────────────────────────────────
    private function crearItemPasajeAereo(Request $request, Alternativa $alternativa): JsonResponse
    {
        $validado = $this->validarPasajeAereo($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $resultado = $this->calcularPasajeAereo($alternativa, $validado);

        $item = DB::transaction(function () use ($alternativa, $validado, $resultado) {
            $item = AlternativaItem::create([
                'alternativa_id' => $alternativa->id,
                'origen_tipo' => AlternativaItem::ORIGEN_PASAJE_AEREO,
                'modo_precio' => 'por_persona', // el reparto adulto/niño/infante ya está resuelto en venta_total
                'cantidad' => 1,
                'pax_incluidos' => $validado['pax_incluidos'] ?? null,
                'moneda_costo' => $validado['moneda'],
                'costo_snapshot' => $resultado['costo_total'],
                'precio_venta_snapshot' => $resultado['venta_total'],
                'precio_convertido' => $this->priceEngine->convertirMoneda($resultado['venta_total'], $validado['moneda'], $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
                'dia_referencial' => $validado['dia_referencial'] ?? null,
            ]);

            CotizacionPasajeAereo::create([
                'alternativa_item_id' => $item->id,
                'aerolinea' => $validado['aerolinea'],
                'itinerario' => $validado['itinerario'] ?? null,
                'moneda' => $validado['moneda'],
                'tarifa_base_adulto' => $validado['tarifa_base_adulto'],
                'tarifa_base_nino' => $validado['tarifa_base_nino'] ?? null,
                'tarifa_base_infante' => $validado['tarifa_base_infante'] ?? null,
                'cargos' => $validado['cargos'] ?? [],
                'tua_incluida_en_tarifa' => $validado['tua_incluida_en_tarifa'] ?? false,
                'fee_agencia_monto' => $validado['fee_agencia_monto'] ?? 0,
                'tip_afe_igv' => $validado['tip_afe_igv'] ?? null,
                'fecha_cotizado' => now(),
                'costo_total' => $resultado['costo_total'],
                'precio_venta_total' => $resultado['venta_total'],
            ]);

            return $item;
        });

        $this->recalcularTotalAlternativa($alternativa);
        $item->load('cotizacionPasajeAereo');

        return response()->json(['code' => 200, 'message' => 'Pasaje aéreo agregado correctamente', 'alternativa_item' => $item]);
    }

    private function validarPasajeAereo(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'aerolinea' => 'required|string|max:150',
            'itinerario' => 'nullable|string',
            'moneda' => 'required|in:PEN,USD',
            'tarifa_base_adulto' => 'required|numeric|min:0',
            'tarifa_base_nino' => 'nullable|numeric|min:0',
            'tarifa_base_infante' => 'nullable|numeric|min:0',
            'cargos' => 'nullable|array',
            'cargos.*.nombre' => 'required_with:cargos|string',
            'cargos.*.monto' => 'required_with:cargos|numeric',
            'cargos.*.tipo' => 'nullable|in:impuesto,tasa_aeropuerto,fee_agencia',
            'tua_incluida_en_tarifa' => 'nullable|boolean',
            'fee_agencia_monto' => 'nullable|numeric|min:0',
            'tip_afe_igv' => 'nullable|string|max:2',
            'pax_incluidos' => 'nullable|array',
            'dia_referencial' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        return $validator->validated();
    }

    private function calcularPasajeAereo(Alternativa $alternativa, array $validado): array
    {
        $conteo = $this->contarPasajerosPorTipo($alternativa->cotizacion_id, $validado['pax_incluidos'] ?? null);

        // tarifa_base_* se reparte por tipo de pasajero, igual que el
        // reparto por_persona de proveedor_tarifas (§3). Los montos de
        // `cargos` los carga el vendedor YA totalizados para el grupo (no
        // se vuelven a multiplicar acá — ver PasajeAereoForm.vue).
        $costoBaseTarifas = $conteo['adulto'] * (float) $validado['tarifa_base_adulto']
            + $conteo['nino'] * (float) ($validado['tarifa_base_nino'] ?? 0)
            + $conteo['infante'] * (float) ($validado['tarifa_base_infante'] ?? 0);

        // fee_agencia_monto es lo único que la agencia realmente vende
        // (§2.5) — se modela como margen 'fijo' del motor de precios, no
        // como un cargo más (los cargos son pass-through de terceros).
        return $this->priceEngine->calcular(
            costoBase: $costoBaseTarifas,
            cargos: $validado['cargos'] ?? [],
            margenTipo: 'fijo',
            margenValor: (float) ($validado['fee_agencia_monto'] ?? 0),
            descuentoMaximoPct: null,
            margenMinimoPct: null,
        );
    }

    // ── origen_tipo=manual ──────────────────────────────────────────────
    private function crearItemManual(Request $request, Alternativa $alternativa): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'descripcion_manual' => 'required|string|max:500',
            'precio_venta_snapshot' => 'required|numeric|min:0',
            'moneda_costo' => 'required|in:PEN,USD',
            'dia_referencial' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'origen_tipo' => AlternativaItem::ORIGEN_MANUAL,
            'descripcion_manual' => $validado['descripcion_manual'],
            'modo_precio' => 'tarifa_fija', // sin efecto real (el accessor ignora cantidad para 'manual'), solo un valor válido para la columna NOT NULL
            'cantidad' => 1,
            // costo_snapshot es NOT NULL desde Sesión 7a — un ítem manual no
            // tiene proveedor del que derivar costo real, 0 es el sentinel
            // (mismo espíritu que "sin piso protegido": no hay costo
            // rastreable de terceros, es 100% precio a criterio del vendedor).
            'costo_snapshot' => 0,
            'moneda_costo' => $validado['moneda_costo'],
            'precio_venta_snapshot' => $validado['precio_venta_snapshot'],
            'precio_convertido' => $this->priceEngine->convertirMoneda((float) $validado['precio_venta_snapshot'], $validado['moneda_costo'], $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
            'dia_referencial' => $validado['dia_referencial'] ?? null,
        ]);

        $this->recalcularTotalAlternativa($alternativa);

        return response()->json(['code' => 200, 'message' => 'Ítem manual agregado correctamente', 'alternativa_item' => $item]);
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

    private function recalcularTotalAlternativa(Alternativa $alternativa): void
    {
        $total = $alternativa->items()->get()->sum(fn (AlternativaItem $item) => $item->total_convertido);
        $alternativa->update(['total' => round($total, 2)]);
    }

}
