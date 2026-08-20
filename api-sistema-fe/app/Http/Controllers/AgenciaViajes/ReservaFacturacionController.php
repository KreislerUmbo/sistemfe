<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SaleDetailItem;
use App\Models\Client\Client;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Services\SerieComprobanteService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Fase A del plan "Proceso de reserva: facturación + 3 fixes" (2026-08-19)
// — plan-modulo-cotizaciones-reservas.md §4.3/§4.4. Cierra el gap real de
// que ninguna reserva podía convertirse nunca en un Sale/comprobante SUNAT
// (reserva_ventas/sale_detail_items existían como schema desde Sesión
// 8a/9b, sin ningún controller que las usara).
//
// Alcance de ESTA fase (decisiones confirmadas con el usuario, ver el plan
// completo en el historial de la sesión — no reabrir sin decisión nueva):
// - Solo "Camino 2" del plan (documento adicional/primera factura). El
//   "Camino 1" (reemplazar con Nota de Crédito cuando ya existe una venta
//   previa de la reserva) es la fase de "editar una reserva ya facturada",
//   fuera de acá.
// - La venta nace PENDIENTE de cobro (state_payment=1, debt=total,
//   paid_out=0) — cobrar es responsabilidad del flujo normal ya existente
//   (Cuentas por Cobrar / editar venta), esta fase no toca Caja.
// - Solo contado (type_payment=1, condicion_pago='contado') — crédito/
//   cuotas queda para una fase futura aparte.
// - Líneas agrupadas por categoría (máx. 5: hotel/transporte/tour/vuelo/
//   otros), no 1 línea por reserva_item — cada línea usa uno de los 5
//   productos placeholder de ProductoGenericoViajeSeeder (Sesión 9a).
// - No aplica ReservaAnticipo/Advance automáticamente.
// - Reglas tributarias: cada línea usa el tip_afe_igv_default del producto
//   placeholder (uniforme '10' gravado) — no se deriva el tipo de
//   afectación real por proveedor/destino/exportación. destino='nacional'
//   fijo. Simplificación conocida y documentada, pendiente de confirmar
//   con el contador (mismo criterio ya usado por AdvanceController para su
//   propio producto especial de adelantos).
// - Envío a SUNAT sin cambios — sigue siendo manual, vía
//   FacturacionElectronicaController::enviarSunat() ya existente.
//
// Patrón seguido de cerca: AdvanceController::store() — es el único otro
// lugar del proyecto que arma un Sale/SaleDetail a mano fuera del
// formulario normal de POS (mismo motivo: sale_details.product_id es
// NOT NULL con FK real, no existe forma de vender una línea sin producto
// de catálogo real detrás).
//
// Guardia tributario (2026-08-20, complemento a la fase A —
// PEGAR-EN-CLAUDE-CODE-facturar-reserva-guardia-tributario.md): la
// simplificación de arriba (IGV 18% fijo, destino='nacional' fijo para
// TODA la venta) es segura mientras los reserva_items facturados juntos
// tengan el mismo tratamiento tributario real. Si se mezclan (ej. un tour
// exonerado Amazonía con un traslado nacional gravado), la cabecera de un
// único Sale no puede reflejar ambos a la vez — riesgo real de emitir un
// comprobante SUNAT con la exoneración mal calculada. Este guardia NO
// resuelve la mezcla (eso es motor multi-Sale, trabajo mayor, fuera de
// esta sesión) — solo la detecta y bloquea con 422, tanto en el preview
// (prepararFactura) como en el POST real (store), sin confiar en que el
// frontend ya filtró.
//
// Limitación conocida del dato disponible hoy: `destino_tributario` solo
// vive en `proveedor_tarifas` (origen_tipo=proveedor). Los otros 4
// orígenes de un alternativa_item (mayorista, pasaje_aereo, manual, guia)
// no tienen ningún campo tributario propio en el modelo actual — se
// tratan como 'nacional' para este guardia (mismo valor que ya usa la
// cabecera del Sale hoy, así que no cambia el comportamiento existente
// para el caso 100% homogéneo/sin proveedor con destino distinto). Si
// alguno de esos orígenes se mezcla con un proveedor 'amazonia' real, el
// guardia bloquea igual (conservador, no deja pasar la mezcla en
// silencio). Resolver esos 4 orígenes con un dato tributario propio queda
// para cuando se aborde el motor multi-Sale completo.
class ReservaFacturacionController extends Controller
{
    // SKU de los 5 productos placeholder sembrados por
    // ProductoGenericoViajeSeeder (Sesión 9a) — deben estar sembrados en
    // el tenant antes de poder facturar cualquier reserva.
    private const SKU_POR_CATEGORIA = [
        'HOTEL' => 'SERVICIO-HOTEL',
        'TRANSPORTE' => 'SERVICIO-TRANSPORTE',
        'TOUR' => 'SERVICIO-TOUR',
        'VUELO' => 'SERVICIO-VUELO',
        'OTROS' => 'SERVICIO-OTROS-TURISTICOS',
    ];

    // Mismos 2 tipos que SaleController::PERMISOS_EMISION soporta desde el
    // formulario normal — NV explícitamente excluido, una reserva
    // facturada siempre es un documento fiscal real.
    private const PERMISOS_EMISION = [
        '01' => 'emitir_factura',
        '03' => 'emitir_boleta',
    ];

    private const DESTINO_TRIBUTARIO_DEFAULT = 'nacional';

    private const MENSAJE_MEZCLA_TRIBUTARIA = 'La reserva combina servicios con tratamiento tributario '
        . 'distinto (ej. exonerado Amazonía + gravado nacional). No se puede facturar en un solo '
        . 'comprobante todavía — requiere revisión manual con el contador antes de emitir.';

    public function __construct(private SerieComprobanteService $serieComprobanteService)
    {
    }

    // GET reservas/{id}/preparar-factura — preview de solo lectura, no
    // persiste nada. Pensado para que el frontend muestre el desglose (o
    // el bloqueo tributario) ANTES de que el usuario llene el modal de
    // confirmación, no como reemplazo del guardia real de store().
    public function prepararFactura(Request $request, string $id)
    {
        $reserva = Reserva::with(['alternativa.cotizacion'])->findOrFail($id);

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede facturar una reserva activa.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'reserva_item_ids' => 'nullable|array|min:1',
            'reserva_item_ids.*' => 'integer|exists:reserva_items,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $idsSolicitados = $validator->validated()['reserva_item_ids'] ?? null;
        $idsYaFacturados = $this->itemsYaFacturadosIds($reserva);

        if ($idsSolicitados === null) {
            // Sin selección explícita: todos los ítems de la reserva que
            // todavía no fueron facturados en ninguna otra venta — mismo
            // criterio que `itemsFacturables` en el frontend.
            $idsSolicitados = ReservaItem::where('reserva_id', $reserva->id)
                ->whereNotIn('id', $idsYaFacturados)
                ->pluck('id')
                ->all();
        }

        if (empty($idsSolicitados)) {
            return response()->json(['code' => 422, 'message' => 'No hay ítems para facturar.'], 422);
        }

        $items = ReservaItem::with(['proveedorTarifa', 'alternativaItem.proveedorTarifa.proveedorServicio.destinoServicio.servicio'])
            ->whereIn('id', $idsSolicitados)
            ->get();

        $idsAjenos = collect($idsSolicitados)->diff($items->pluck('id'))->values();
        if ($idsAjenos->isNotEmpty() || $items->pluck('reserva_id')->unique()->all() !== [$reserva->id]) {
            return response()->json([
                'code' => 422,
                'message' => 'Todos los reserva_item_ids deben pertenecer a esta reserva.',
            ], 422);
        }

        $idsRepetidos = collect($idsSolicitados)->intersect($idsYaFacturados)->values();
        if ($idsRepetidos->isNotEmpty()) {
            return response()->json([
                'code' => 422,
                'message' => 'Los siguientes ítems ya fueron facturados en otra venta de esta reserva: ' . $idsRepetidos->implode(', '),
            ], 422);
        }

        $bloqueo = $this->detectarMezclaTributaria($items);
        if ($bloqueo !== null) {
            return response()->json(array_merge(['code' => 200], $bloqueo));
        }

        $gruposPorCategoria = $this->agruparPorCategoria($items);

        $productosPlaceholder = Product::whereIn('sku', array_values(self::SKU_POR_CATEGORIA))->get()->keyBy('sku');
        foreach ($gruposPorCategoria as $categoria => $grupo) {
            $sku = self::SKU_POR_CATEGORIA[$categoria];
            if (! $productosPlaceholder->has($sku)) {
                throw new HttpException(
                    500,
                    "No se encontró el producto especial de servicios de viaje '{$sku}'. Revisa que " .
                    "ProductoGenericoViajeSeeder se haya corrido en este tenant."
                );
            }
        }

        [$lineas, $subtotalTotal, $igvTotal] = $this->construirLineas($gruposPorCategoria, $productosPlaceholder);
        $total = round($subtotalTotal + $igvTotal, 2);

        return response()->json([
            'code' => 200,
            'bloqueado_tributario' => false,
            'grupos_propuestos' => collect($lineas)->map(fn (array $linea) => [
                'categoria' => $linea['categoria'],
                'cantidad_items' => $linea['grupo']->count(),
                'subtotal' => $linea['subtotal'],
                'igv' => $linea['igv'],
                'total' => $linea['precio_final'],
            ])->values(),
            'subtotal' => $subtotalTotal,
            'igv' => $igvTotal,
            'total' => $total,
        ]);
    }

    // POST reservas/{id}/facturar
    public function store(Request $request, string $id)
    {
        $reserva = Reserva::with(['alternativa.cotizacion'])->findOrFail($id);

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede facturar una reserva activa.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'reserva_item_ids' => 'required|array|min:1',
            'reserva_item_ids.*' => 'integer|exists:reserva_items,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'tipo_comprobante_codigo' => 'required|string|in:01,03',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $items = ReservaItem::with([
            'proveedorTarifa',
            'alternativaItem.proveedorTarifa.proveedorServicio.destinoServicio.servicio',
        ])->whereIn('id', $validado['reserva_item_ids'])->get();

        $idsAjenos = collect($validado['reserva_item_ids'])->diff($items->pluck('id'))->values();
        if ($idsAjenos->isNotEmpty() || $items->pluck('reserva_id')->unique()->all() !== [$reserva->id]) {
            return response()->json([
                'code' => 422,
                'message' => 'Todos los reserva_item_ids deben pertenecer a esta reserva.',
            ], 422);
        }

        // Guard anti-doble-facturación: ningún ítem pedido puede estar ya
        // cubierto por una ReservaVenta existente de esta reserva. Nunca un
        // fallback silencioso — 422 explícito listando cuáles ya están
        // facturados, para que el vendedor sepa exactamente qué falta.
        $idsYaFacturados = $this->itemsYaFacturadosIds($reserva);

        $idsRepetidos = collect($validado['reserva_item_ids'])->intersect($idsYaFacturados)->values();
        if ($idsRepetidos->isNotEmpty()) {
            return response()->json([
                'code' => 422,
                'message' => 'Los siguientes ítems ya fueron facturados en otra venta de esta reserva: ' . $idsRepetidos->implode(', '),
            ], 422);
        }

        // Guardia tributario — 422 explícito, nunca confía en que el
        // frontend ya lo filtró vía prepararFactura(). Antes de reservar
        // correlativo o abrir la transacción.
        $bloqueo = $this->detectarMezclaTributaria($items);
        if ($bloqueo !== null) {
            return response()->json(array_merge(['code' => 422, 'message' => $bloqueo['motivo']], $bloqueo), 422);
        }

        $usuario = auth('api')->user();
        $permisoRequerido = self::PERMISOS_EMISION[$validado['tipo_comprobante_codigo']];
        if (! $usuario->can($permisoRequerido)) {
            throw new HttpException(403, "No tienes permiso para emitir este tipo de comprobante ('{$permisoRequerido}').");
        }

        $cliente = Client::findOrFail($validado['client_id'] ?? $reserva->alternativa->cotizacion->cliente_id);
        $moneda = $reserva->alternativa->moneda_cotizacion;

        $serieResuelta = $this->serieComprobanteService->resolverParaUsuario(
            $usuario,
            $validado['tipo_comprobante_codigo'],
            $moneda
        );

        $gruposPorCategoria = $this->agruparPorCategoria($items);

        $productosPlaceholder = Product::whereIn('sku', array_values(self::SKU_POR_CATEGORIA))->get()->keyBy('sku');
        foreach ($gruposPorCategoria as $categoria => $grupo) {
            $sku = self::SKU_POR_CATEGORIA[$categoria];
            if (! $productosPlaceholder->has($sku)) {
                throw new HttpException(
                    500,
                    "No se encontró el producto especial de servicios de viaje '{$sku}'. Revisa que " .
                    "ProductoGenericoViajeSeeder se haya corrido en este tenant."
                );
            }
        }

        try {
            [$venta, $lineas] = DB::transaction(function () use (
                $reserva,
                $gruposPorCategoria,
                $productosPlaceholder,
                $cliente,
                $moneda,
                $serieResuelta,
                $usuario,
                $validado
            ) {
                [$lineas, $subtotalTotal, $igvTotal] = $this->construirLineas($gruposPorCategoria, $productosPlaceholder);
                $total = round($subtotalTotal + $igvTotal, 2);

                $venta = Sale::create([
                    'type' => 'sale',
                    'date' => now()->toDateString(),
                    'serie' => $serieResuelta['serie']->serie,
                    'tipo_comprobante_codigo' => $serieResuelta['tipo']->codigo,
                    'serie_comprobante_id' => $serieResuelta['serie']->id,
                    'n_transaction' => Sale::siguienteNumeroTransaccion(),
                    'user_id' => $usuario->id,
                    'client_id' => $cliente->id,
                    'type_client' => $cliente->type_client,
                    'cod_tipo_doc_cliente' => $cliente->cod_tipo_doc_sunat,
                    'currency' => $moneda,
                    'is_exportacion' => 0,
                    'destino' => 'nacional',
                    'state_sale' => 1,
                    'type_payment' => 1,
                    'condicion_pago' => 'contado',
                    'subtotal' => $subtotalTotal,
                    'igv' => $igvTotal,
                    'total' => $total,
                    'discount' => 0,
                    'discount_global' => 0,
                    'mto_oper_gravadas' => $subtotalTotal,
                    'mto_oper_exoneradas' => 0,
                    'mto_oper_inafectas' => 0,
                    'mto_oper_exportacion' => 0,
                    'mto_oper_gratuitas' => 0,
                    'isc_total' => 0,
                    'icbper_total' => 0,
                    'ivap_total' => 0,
                    'total_impuestos' => $igvTotal,
                    'valor_venta' => $subtotalTotal,
                    'mto_imp_venta' => $total,
                    'redondeo' => 0,
                    'retencion_igv' => 0,
                    'monto_retencion' => 0,
                    'monto_detraccion' => 0,
                    'monto_percepcion' => 0,
                    'state_payment' => 1,
                    'debt' => $total,
                    'paid_out' => 0,
                    // saldo_pendiente: espejo vivo de debt para que la venta
                    // aparezca correctamente en Cuentas por Cobrar — mismo
                    // fix ya aplicado en SaleController::store()/update()
                    // (ver CLAUDE.md, "sales.debt/paid_out como snapshot
                    // congelado"), no se puede omitir acá tampoco.
                    'saldo_pendiente' => $total,
                    'description' => "Facturación de reserva #{$reserva->id} ({$reserva->alternativa->cotizacion->codigo})",
                ]);

                $reservaItemIdsFacturados = [];

                foreach ($lineas as $linea) {
                    $producto = $linea['producto'];

                    $saleDetail = SaleDetail::create([
                        'sale_id' => $venta->id,
                        'product_id' => $producto->id,
                        'product_categorie_id' => $producto->categorie_id,
                        'unidad_medida' => $producto->unidad_medida,
                        'quantity' => 1,
                        'price_base' => $linea['subtotal'],
                        'price_final' => $linea['precio_final'],
                        'discount' => 0,
                        'subtotal' => $linea['subtotal'],
                        'mto_valor_venta' => $linea['subtotal'],
                        'mto_base_igv' => $linea['subtotal'],
                        'porcentaje_igv' => $linea['porcentaje_igv'],
                        'igv' => $linea['igv'],
                        'tip_afe_igv' => (string) $producto->tip_afe_igv_default,
                        'total_impuestos' => $linea['igv'],
                        'description' => $producto->title,
                        'descripcion_detalle' => $linea['descripcion_detalle'],
                        'percentage_isc' => 0,
                        'isc' => 0,
                        'tipo_isc' => null,
                        'monto_isc_fijo' => 0,
                        'per_icbper' => 0,
                        'icbper' => 0,
                    ]);

                    foreach ($linea['grupo'] as $reservaItem) {
                        SaleDetailItem::create([
                            'sale_detail_id' => $saleDetail->id,
                            'reserva_item_id' => $reservaItem->id,
                        ]);
                        $reservaItemIdsFacturados[] = $reservaItem->id;
                    }
                }

                ReservaVenta::create([
                    'reserva_id' => $reserva->id,
                    'sale_id' => $venta->id,
                    'reserva_item_ids' => $reservaItemIdsFacturados,
                    'reserva_pasajero_ids' => $reserva->pasajeros()->pluck('id')->all(),
                ]);

                return [$venta, $lineas];
            });
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $error) {
            throw new HttpException(500, $error->getMessage());
        }

        return response()->json([
            'code' => 200,
            'message' => 'Reserva facturada correctamente. La venta queda pendiente de cobro.',
            'sale_id' => $venta->id,
            'serie' => $venta->serie,
            'lineas' => count($lineas),
        ]);
    }

    // Ningún reserva_item pedido puede estar ya cubierto por una
    // ReservaVenta existente de esta reserva — compartido por
    // prepararFactura() y store().
    private function itemsYaFacturadosIds(Reserva $reserva): Collection
    {
        return ReservaVenta::where('reserva_id', $reserva->id)
            ->get()
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_item_ids ?? [])
            ->unique()
            ->values();
    }

    // Fuente de verdad: el proveedor_tarifa_id PROPIO del reserva_item
    // (retrofit Sesión 11c — "quién opera", reasignable cerca de la
    // fecha), no el de alternativa_item (la propuesta comercial original,
    // puede quedar desactualizada). Se siembra desde alternativa_item al
    // crear el reserva_item y se actualiza si se reasigna el proveedor —
    // ver ReservaController::crearReservaItemDesdeAlternativaItem().
    //
    // Devuelve 'nacional' cuando no hay proveedor_tarifa (orígenes
    // mayorista/pasaje_aereo/manual/guia, ninguno de los 4 tiene un campo
    // tributario propio hoy) — ver nota de la clase sobre esta limitación.
    private function resolverDestinoTributario(ReservaItem $item): string
    {
        return $item->proveedorTarifa?->destino_tributario ?? self::DESTINO_TRIBUTARIO_DEFAULT;
    }

    // Guardia tributario: null si todos los ítems comparten el mismo
    // destino_tributario efectivo (caso normal, la mayoría de las
    // reservas reales); si no, la estructura de bloqueo lista para la
    // respuesta HTTP (sin el wrapper code/message, cada caller decide
    // status).
    private function detectarMezclaTributaria(Collection $items): ?array
    {
        $destinos = $items->map(fn (ReservaItem $it) => $this->resolverDestinoTributario($it))->unique()->values();

        if ($destinos->count() <= 1) {
            return null;
        }

        return [
            'bloqueado_tributario' => true,
            'motivo' => self::MENSAJE_MEZCLA_TRIBUTARIA,
            'destinos_tributarios_detectados' => $destinos->all(),
        ];
    }

    // Agrupa reserva_items por categoría (HOTEL/TRANSPORTE/TOUR/VUELO/
    // OTROS vía clasificarCategoria()), preservando ese orden para que las
    // líneas del comprobante/preview salgan estables — no en el orden
    // arbitrario en que llegaron los ids. Descarta categorías vacías.
    private function agruparPorCategoria(Collection $items): array
    {
        $grupos = [];
        foreach (array_keys(self::SKU_POR_CATEGORIA) as $categoria) {
            $grupos[$categoria] = collect();
        }
        foreach ($items as $item) {
            $grupos[$this->clasificarCategoria($item)]->push($item);
        }

        return array_filter($grupos, fn (Collection $grupo) => $grupo->isNotEmpty());
    }

    // Cálculo puro (sin tocar BD) de las líneas del comprobante a partir
    // de los grupos ya armados — compartido por el preview de solo lectura
    // (prepararFactura) y la creación real (store, dentro de su
    // transacción). Devuelve [lineas, subtotalTotal, igvTotal].
    private function construirLineas(array $gruposPorCategoria, Collection $productosPlaceholder): array
    {
        $porcentajeIgv = 18.0;
        $subtotalTotal = 0;
        $igvTotal = 0;
        $lineas = [];

        foreach ($gruposPorCategoria as $categoria => $grupo) {
            $producto = $productosPlaceholder->get(self::SKU_POR_CATEGORIA[$categoria]);

            $precioFinalLinea = round($grupo->sum(fn (ReservaItem $it) => (float) $it->alternativaItem->total_convertido), 2);
            $subtotalLinea = round($precioFinalLinea / (1 + $porcentajeIgv / 100), 2);
            $igvLinea = round($precioFinalLinea - $subtotalLinea, 2);

            $descripcionDetalle = $grupo->map(fn (ReservaItem $it) => sprintf(
                '%s (%s)',
                ReservaController::resolverNombreItem($it->alternativaItem),
                $it->fecha?->toDateString() ?? 'sin fecha'
            ))->implode('; ');

            $subtotalTotal += $subtotalLinea;
            $igvTotal += $igvLinea;

            $lineas[] = [
                'categoria' => $categoria,
                'producto' => $producto,
                'grupo' => $grupo,
                'subtotal' => $subtotalLinea,
                'igv' => $igvLinea,
                'precio_final' => $precioFinalLinea,
                'porcentaje_igv' => $porcentajeIgv,
                'descripcion_detalle' => $descripcionDetalle,
            ];
        }

        return [$lineas, round($subtotalTotal, 2), round($igvTotal, 2)];
    }

    // Heurística best-effort, documentada como tal (no una regla de
    // negocio exacta) — clasifica un reserva_item en una de las 5
    // categorías de ProductoGenericoViajeSeeder. OTROS-TURISTICOS es el
    // catch-all para todo lo que no calza claramente en las otras 4
    // (entradas, comidas, guía, ítems manuales, mayorista).
    private function clasificarCategoria(ReservaItem $item): string
    {
        $alternativaItem = $item->alternativaItem;

        if ($alternativaItem?->proveedorTarifa?->tipo_habitacion) {
            return 'HOTEL';
        }

        if ($alternativaItem?->origen_tipo === AlternativaItem::ORIGEN_PASAJE_AEREO) {
            return 'VUELO';
        }

        $nombreServicio = mb_strtolower(
            $alternativaItem?->proveedorTarifa?->proveedorServicio?->destinoServicio?->servicio?->nombre ?? ''
        );

        if (str_contains($nombreServicio, 'transporte') || str_contains($nombreServicio, 'traslado')) {
            return 'TRANSPORTE';
        }

        if (str_contains($nombreServicio, 'tour')) {
            return 'TOUR';
        }

        return 'OTROS';
    }
}
