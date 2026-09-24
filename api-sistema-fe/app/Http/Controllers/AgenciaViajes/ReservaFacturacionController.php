<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaAnticipo;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SaleDetailItem;
use App\Models\Client\Client;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Services\AdvanceApplicationService;
use App\Services\AgenciaViajes\PriceEngineService;
use App\Services\SerieComprobanteService;
use App\Services\TipoCambio\TipoCambioSunatResolver;
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
// Guardia tributario (2026-08-20, complemento a la fase A —
// PEGAR-EN-CLAUDE-CODE-facturar-reserva-guardia-tributario.md): un único
// Sale no puede reflejar dos tratamientos tributarios distintos a la vez
// — se detecta y bloquea la mezcla (nunca se resuelve automáticamente).
//
// Facturación múltiple por grupo de pasajeros (2026-08-20, mismo día,
// PEGAR-EN-CLAUDE-CODE-facturacion-multiple-pasajeros.md): la Fase A
// original asumía un solo responsable de pago por reserva completa — un
// único Sale, cliente fijo a cotizacion.cliente_id, guard de "reserva ya
// facturada" a nivel de RESERVA completa. Eso no soporta el caso real de
// grupos (20 pasajeros, cada uno pide su propio comprobante a su nombre o
// a una empresa distinta). Ahora: N Sales por reserva, cada uno cubriendo
// un subconjunto de pasajeros elegido por el vendedor, con su propio
// client_id (obligatorio, sin default) y texto_personalizado opcional.
//
// Diseño de selección de ítems para un subconjunto de pasajeros (decidido
// con el usuario tras confirmar con datos reales que la mayoría de
// reserva_items HOY no tienen ninguna fila en reserva_item_pasajero — 11
// de 37 en todo el tenant agencia-demo, 8 de 8 en la reserva de prueba
// usada para el guardia tributario; construir el guard asumiendo
// vinculación completa habría dejado la función inutilizable con datos
// reales):
// - Ítem CON pasajeros vinculados (reserva_item_pasajero): se auto-incluye
//   en la facturación de un subconjunto SOLO si TODOS sus pasajeros
//   vinculados están dentro del subconjunto seleccionado — nunca se
//   fragmenta un ítem compartido entre dos Sales distintos. Si falta
//   alguno, el ítem queda fuera de este pase y se informa por qué
//   (items_pendientes_por_pasajero_faltante).
// - Ítem SIN ningún pasajero vinculado (el caso más común hoy: ajustes,
//   ítems manuales, la mayoría del catálogo real): NO se auto-incluye por
//   selección de pasajero — se ofrece aparte como
//   items_sin_asignar_disponibles y el vendedor lo agrega explícitamente
//   vía reserva_item_ids_manual si corresponde a este Sale. Evita que la
//   función quede vacía para la mayoría de reservas reales (que no usan
//   la pestaña "Asignación pasajero↔ítem"), sin inventar una atribución
//   automática que nadie pidió.
// - Fuera de alcance, documentado, no resuelto: reparto de un ítem
//   tarifa_fija compartido (ej. habitación doble) entre pasajeros que
//   terminan en Sales DISTINTOS — no existe ningún mecanismo en el
//   proyecto que sepa cuánto le toca a cada uno (confirmado por
//   investigación de código antes de programar esta sesión, no asumido).
//   Si eso pasa, el ítem queda permanentemente sin poder facturarse por
//   este flujo hasta resolución manual (reasignar en la pestaña de
//   asignación, o facturarlo como ítem sin asignar a criterio del
//   vendedor). No se improvisó ninguna fórmula de reparto.
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

    // Caso 4 (mezcla) sigue sin responder — sin cambios acá, ver arriba.
    // Caso "extranjero"/exportación (2026-09-24): sigue bloqueado sin
    // ninguna excepción — requiere validaciones legales que este flujo no
    // hace (cliente no domiciliado, consumo fuera del país, medios
    // bancarizados), fuera de alcance de este fix.
    private const MENSAJE_TRATAMIENTO_TRIBUTARIO_EXTRANJERO = 'Esta reserva incluye servicios de exportación/extranjero. '
        . 'La facturación de estos casos está pausada — requiere validaciones legales (cliente no domiciliado, medios '
        . 'bancarizados) que este flujo todavía no hace. Contacta a soporte.';

    // Caso 3 (Amazonía) confirmado con el usuario (2026-09-24): un
    // servicio realizado en la Amazonía está exonerado de IGV según Ley
    // 27037, independientemente del domicilio fiscal de la agencia. Pero
    // nunca se factura solo porque destino_tributario llegó así copiado de
    // la proveedor_tarifa (dato que ya se encontró sospechoso al menos una
    // vez para un producto real) — se exige que un humano lo confirme
    // ítem por ítem primero, ver ReservaController::
    // overrideTratamientoTributario() / ReservaItem::motivo_override_tributario.
    private const MENSAJE_AMAZONIA_SIN_CONFIRMAR = 'Esta reserva incluye servicios de la Amazonía (exonerados de IGV '
        . 'por Ley 27037), pero todavía no fueron confirmados uno por uno. Confirmá el tratamiento tributario de cada '
        . 'servicio antes de poder facturarlos.';

    // Facturación externa por tenant (PEGAR-EN-CLAUDE-CODE-facturacion-externa-
    // tenant.md §3.1) — doble capa: el frontend ya oculta el botón "Facturar"
    // cuando tenants.facturacion_habilitada es falsy, pero este endpoint nunca
    // confía en que el frontend filtró bien. tenant('facturacion_habilitada') es
    // null-safe (vendor/stancl/tenancy/src/helpers.php) — sin tenancy
    // inicializada resuelve null, tratado igual que false (deny-by-default).
    private const MENSAJE_FACTURACION_NO_HABILITADA = 'Este tenant no tiene habilitada la facturación '
        . 'electrónica en la plataforma (modelo "solo operativo"). Contactá a soporte/tu superadmin si '
        . 'necesitás activarla.';

    // reserva.facturacion_externa (§3.2) es el override por-reserva: si está
    // marcada, esta reserva ya se facturó fuera de la plataforma y no debe
    // poder generar un comprobante interno real por encima — mismo criterio
    // de "el backend nunca confía en que el frontend ocultó el botón" que ya
    // aplica al flag del tenant arriba.
    private const MENSAJE_FACTURACION_EXTERNA = 'Esta reserva está marcada como facturada externamente '
        . '(fuera de la plataforma). Si fue un error, desmárcala primero desde el detalle de la reserva '
        . 'antes de facturar acá.';

    public function __construct(
        private SerieComprobanteService $serieComprobanteService,
        private AdvanceApplicationService $advanceApplicationService,
        private PriceEngineService $priceEngineService,
        private TipoCambioSunatResolver $tipoCambioSunatResolver
    ) {
    }

    // GET reservas/{id}/preparar-factura?pasajero_ids[]=5&pasajero_ids[]=6
    // &reserva_item_ids_manual[]=49 — preview de solo lectura, no persiste
    // nada. Pensado para que el frontend muestre el desglose (o el
    // bloqueo tributario) ANTES de que el usuario confirme, no como
    // reemplazo del guardia real de store().
    public function prepararFactura(Request $request, string $id)
    {
        $reserva = Reserva::with(['alternativa.cotizacion', 'pasajeros', 'ventas'])->findOrFail($id);

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede facturar una reserva activa.'], 422);
        }

        if (! tenant('facturacion_habilitada')) {
            throw new HttpException(403, self::MENSAJE_FACTURACION_NO_HABILITADA);
        }

        if ($reserva->facturacion_externa) {
            throw new HttpException(403, self::MENSAJE_FACTURACION_EXTERNA);
        }

        $validator = Validator::make($request->all(), [
            'pasajero_ids' => 'required|array|min:1',
            'pasajero_ids.*' => 'integer|exists:reserva_pasajeros,id',
            'reserva_item_ids_manual' => 'nullable|array',
            'reserva_item_ids_manual.*' => 'integer|exists:reserva_items,id',
            // Facturar en moneda distinta a la de la cotización (2026-09-24,
            // ver resolverMonedaFacturacionPreview()) — sin motivo/tipo de
            // cambio obligatorios acá, el preview es de solo lectura y
            // sugiere un tipo de cambio si no se pasó ninguno.
            'moneda_facturacion' => 'nullable|string|in:PEN,USD',
            'tipo_cambio_conversion' => 'nullable|numeric|gt:0',
            // Serie visible en el preview (2026-09-24, pedido del usuario:
            // "debe aparecer de manera visible la serie") — ambos opcionales,
            // sin ellos no se resuelve ninguna serie (el vendedor todavía no
            // eligió tipo de comprobante en este preview).
            'branch_id' => 'nullable|integer|exists:branches,id',
            'tipo_comprobante_codigo' => 'nullable|string|in:01,03',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $resolucion = $this->resolverSeleccion($reserva, $validado['pasajero_ids'], $validado['reserva_item_ids_manual'] ?? []);
        if ($resolucion instanceof \Illuminate\Http\JsonResponse) {
            return $resolucion;
        }
        ['items' => $items, 'contexto' => $contexto] = $resolucion;

        $infoMoneda = $this->resolverMonedaFacturacionPreview($request, $reserva);
        $contexto = array_merge($contexto, [
            'moneda_cotizacion_original' => $infoMoneda['moneda_original'],
            'moneda_facturacion' => $infoMoneda['moneda_facturacion'],
            'tipo_cambio_sugerido' => $infoMoneda['tipo_cambio_sugerido'],
            'tipo_cambio_aplicado' => $infoMoneda['tipo_cambio'],
            'serie_resuelta' => $this->resolverSeriePreview($request, $validado, $infoMoneda['moneda_facturacion']),
        ]);

        // Preview de solo lectura: se mantiene siempre 200, con
        // bloqueado_tributario en el body — nunca una excepción. El
        // frontend depende de este contrato para mostrar el motivo dentro
        // del propio modal (ver detalle.vue) en vez de un toast genérico.
        // store() sí lanza HttpException para el mismo bloqueo (abajo),
        // porque ahí SÍ es un intento real de escritura que debe abortar.
        $bloqueo = $this->detectarMezclaTributaria($items);
        if ($bloqueo !== null) {
            return response()->json(array_merge(['code' => 200], $bloqueo, $contexto));
        }

        $gruposPorCategoria = $this->agruparPorCategoria($items);

        $productosPlaceholder = Product::whereIn('sku', array_values(self::SKU_POR_CATEGORIA))->get()->keyBy('sku');
        foreach ($gruposPorCategoria as $grupo) {
            $sku = self::SKU_POR_CATEGORIA[$grupo['categoria']];
            if (! $productosPlaceholder->has($sku)) {
                throw new HttpException(
                    500,
                    "No se encontró el producto especial de servicios de viaje '{$sku}'. Revisa que " .
                    "ProductoGenericoViajeSeeder se haya corrido en este tenant."
                );
            }
        }

        [$lineas, $subtotalTotal, $igvTotal] = $this->construirLineas($gruposPorCategoria, $productosPlaceholder);

        if ($infoMoneda['moneda_facturacion'] !== $infoMoneda['moneda_original'] && $infoMoneda['tipo_cambio'] !== null) {
            [$lineas, $subtotalTotal, $igvTotal] = $this->convertirLineasDeMoneda(
                $lineas,
                $subtotalTotal,
                $igvTotal,
                $infoMoneda['moneda_original'],
                $infoMoneda['moneda_facturacion'],
                $infoMoneda['tipo_cambio']
            );
        }

        $total = round($subtotalTotal + $igvTotal, 2);

        // Tier 0 — conexión Adelantos↔Reservas: solo lectura, para que el
        // frontend pueda pintar el picker de "Facturación especial" antes
        // de confirmar. No aplica nada.
        $anticiposDisponibles = $reserva->anticipos()->with('advance')->get()
            ->map(fn (ReservaAnticipo $ra) => [
                'id' => $ra->id,
                'advance_id' => $ra->advance_id,
                'disponible' => $ra->advance->availableBalance(),
                'moneda' => $ra->advance->currency,
            ])
            ->filter(fn (array $a) => $a['disponible'] > 0)
            ->values();

        return response()->json(array_merge([
            'code' => 200,
            'bloqueado_tributario' => false,
            // Siempre visible, no solo cuando bloquea (pedido del usuario,
            // 2026-09-24) — ver mapearItemsPorTratamientoTributario().
            'items_por_destino_tributario' => $this->mapearItemsPorTratamientoTributario($items),
            'grupos_propuestos' => collect($lineas)->map(fn (array $linea) => [
                'categoria' => $linea['categoria'],
                'cantidad_items' => $linea['grupo']->count(),
                'subtotal' => $linea['subtotal'],
                'igv' => $linea['igv'],
                'total' => $linea['precio_final'],
            ])->values(),
            'anticipos_disponibles' => $anticiposDisponibles,
            'subtotal' => $subtotalTotal,
            'igv' => $igvTotal,
            'total' => $total,
        ], $contexto));
    }

    // POST reservas/{id}/facturar
    public function store(Request $request, string $id)
    {
        $reserva = Reserva::with(['alternativa.cotizacion', 'pasajeros', 'ventas'])->findOrFail($id);

        if ($reserva->estado !== 'activa') {
            return response()->json(['code' => 422, 'message' => 'Solo se puede facturar una reserva activa.'], 422);
        }

        if (! tenant('facturacion_habilitada')) {
            throw new HttpException(403, self::MENSAJE_FACTURACION_NO_HABILITADA);
        }

        if ($reserva->facturacion_externa) {
            throw new HttpException(403, self::MENSAJE_FACTURACION_EXTERNA);
        }

        $validator = Validator::make($request->all(), [
            'pasajero_ids' => 'required|array|min:1',
            'pasajero_ids.*' => 'integer|exists:reserva_pasajeros,id',
            'reserva_item_ids_manual' => 'nullable|array',
            'reserva_item_ids_manual.*' => 'integer|exists:reserva_items,id',
            'client_id' => 'required|integer|exists:clients,id',
            'tipo_comprobante_codigo' => 'required|string|in:01,03',
            'texto_personalizado' => 'nullable|string|max:2000',
            // Solo tiene efecto si el usuario tiene can_switch_branch (ver
            // más abajo) — sin ese permiso, se ignora y se usa la
            // sucursal propia del usuario, mismo criterio que SaleController.
            'branch_id' => 'nullable|integer|exists:branches,id',
            // Facturar en moneda distinta a la de la cotización (2026-09-24):
            // moneda_facturacion es opcional (default = la de la cotización);
            // si se pide otra, tipo_cambio_conversion y motivo_cambio_moneda
            // son obligatorios — se valida a mano abajo (resolverMonedaFacturacion())
            // porque "obligatorio si difiere de un valor que no es un campo del
            // request" no es expresable con required_if.
            'moneda_facturacion' => 'nullable|string|in:PEN,USD',
            'tipo_cambio_conversion' => 'nullable|numeric|gt:0',
            'motivo_cambio_moneda' => 'nullable|string|max:2000',
            // Tier 0 — conexión Adelantos↔Reservas: vacío = "Facturar"
            // simple (el backend auto-aplica el 100% de los anticipos
            // disponibles de la reserva); poblado = "Facturación especial"
            // (el vendedor eligió a mano cuáles y cuánto, mismo shape que
            // SaleController::store() ya acepta).
            'advance_applications' => 'nullable|array',
            'advance_applications.*.advance_id' => 'required_with:advance_applications|integer',
            'advance_applications.*.amount' => 'required_with:advance_applications|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $resolucion = $this->resolverSeleccion($reserva, $validado['pasajero_ids'], $validado['reserva_item_ids_manual'] ?? []);
        if ($resolucion instanceof \Illuminate\Http\JsonResponse) {
            return $resolucion;
        }
        ['items' => $items] = $resolucion;

        if ($items->isEmpty()) {
            return response()->json([
                'code' => 422,
                'message' => 'No hay ningún ítem para facturar con esta selección de pasajeros — revisa si faltan '
                    . 'ítems "sin asignar" por elegir a mano, o si algún ítem compartido necesita incluir también '
                    . 'a los pasajeros que lo comparten.',
            ], 422);
        }

        $bloqueo = $this->detectarMezclaTributaria($items);
        if ($bloqueo !== null) {
            return response()->json(array_merge(['code' => 422, 'message' => $bloqueo['motivo']], $bloqueo), 422);
        }

        $usuario = auth('api')->user();
        $permisoRequerido = self::PERMISOS_EMISION[$validado['tipo_comprobante_codigo']];
        if (! $usuario->can($permisoRequerido)) {
            throw new HttpException(403, "No tienes permiso para emitir este tipo de comprobante ('{$permisoRequerido}').");
        }

        $cliente = Client::findOrFail($validado['client_id']);
        if ($validado['tipo_comprobante_codigo'] === '01' && (string) $cliente->cod_tipo_doc_sunat !== '6') {
            throw new HttpException(422, 'No se puede emitir Factura a un cliente sin RUC. Selecciona Boleta o cambia el cliente.');
        }

        $infoMoneda = $this->resolverMonedaFacturacion($request, $reserva);
        if ($infoMoneda instanceof \Illuminate\Http\JsonResponse) {
            return $infoMoneda;
        }
        $moneda = $infoMoneda['moneda_facturacion'];

        // Pedido del usuario (2026-09-24): la agencia factura en PEN y USD
        // con series distintas para mejor control — mismo patrón exacto
        // que SaleController::store() (resolverSerieComprobante()): sin
        // esto, el vendedor con permiso can_switch_branch no tenía forma
        // de elegir la sucursal/serie desde Reservas (solo podía desde
        // Venta Directa/ventas normales). El servicio revalida el permiso
        // internamente, así que pasar branch_id sin tener el permiso no
        // hace nada — cae en el branch_id propio del usuario igual.
        $branchIdSolicitado = ($usuario->can('can_switch_branch') && $request->filled('branch_id'))
            ? (int) $request->branch_id
            : null;

        $serieResuelta = $this->serieComprobanteService->resolverParaUsuario(
            $usuario,
            $validado['tipo_comprobante_codigo'],
            $moneda,
            $branchIdSolicitado
        );

        $gruposPorCategoria = $this->agruparPorCategoria($items);

        $productosPlaceholder = Product::whereIn('sku', array_values(self::SKU_POR_CATEGORIA))->get()->keyBy('sku');
        foreach ($gruposPorCategoria as $grupo) {
            $sku = self::SKU_POR_CATEGORIA[$grupo['categoria']];
            if (! $productosPlaceholder->has($sku)) {
                throw new HttpException(
                    500,
                    "No se encontró el producto especial de servicios de viaje '{$sku}'. Revisa que " .
                    "ProductoGenericoViajeSeeder se haya corrido en este tenant."
                );
            }
        }

        $textoPersonalizado = $validado['texto_personalizado'] ?? null;
        $pasajeroIdsSolicitados = $validado['pasajero_ids'];
        $idsManualSolicitados = $validado['reserva_item_ids_manual'] ?? [];
        $aplicacionesAdelantoSolicitadas = $validado['advance_applications'] ?? [];

        try {
            [$venta, $lineas] = DB::transaction(function () use (
                $reserva,
                $gruposPorCategoria,
                $productosPlaceholder,
                $cliente,
                $serieResuelta,
                $aplicacionesAdelantoSolicitadas,
                $usuario,
                $textoPersonalizado,
                $pasajeroIdsSolicitados,
                $idsManualSolicitados,
                $infoMoneda
            ) {
                // Re-chequeo bajo lock: cierra la ventana de carrera con
                // ReservaController::actualizarFacturacionExterna() sobre la
                // misma reserva — el check de arriba corrió antes de abrir
                // la transacción, así que sin esto un PUT
                // facturacion-externa concurrente podría colarse entre ese
                // check y la creación real del Sale.
                $reservaLocked = Reserva::with(['alternativa.cotizacion', 'pasajeros.pasajeroCatalogo.cliente', 'ventas'])
                    ->where('id', $reserva->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($reservaLocked->facturacion_externa) {
                    throw new HttpException(403, self::MENSAJE_FACTURACION_EXTERNA);
                }

                $resolucionBajoLock = $this->resolverSeleccion(
                    $reservaLocked,
                    $pasajeroIdsSolicitados,
                    $idsManualSolicitados
                );
                if ($resolucionBajoLock instanceof \Illuminate\Http\JsonResponse) {
                    throw new HttpException(422, $resolucionBajoLock->getData(true)['message']);
                }

                ['items' => $itemsBajoLock] = $resolucionBajoLock;
                if ($itemsBajoLock->isEmpty()) {
                    throw new HttpException(422, 'No hay ningún ítem disponible para facturar con esta selección.');
                }

                $bloqueoBajoLock = $this->detectarMezclaTributaria($itemsBajoLock);
                if ($bloqueoBajoLock !== null) {
                    throw new HttpException(422, $bloqueoBajoLock['motivo']);
                }

                $gruposPorCategoriaBajoLock = $this->agruparPorCategoria($itemsBajoLock);
                foreach ($gruposPorCategoriaBajoLock as $grupo) {
                    $sku = self::SKU_POR_CATEGORIA[$grupo['categoria']];
                    if (! $productosPlaceholder->has($sku)) {
                        throw new HttpException(500, "No se encontró el producto especial de servicios de viaje '{$sku}'. Revisa que ProductoGenericoViajeSeeder se haya corrido en este tenant.");
                    }
                }

                [$lineas, $subtotalTotal, $igvTotal] = $this->construirLineas($gruposPorCategoriaBajoLock, $productosPlaceholder, $textoPersonalizado);

                if ($infoMoneda['moneda_facturacion'] !== $infoMoneda['moneda_original']) {
                    [$lineas, $subtotalTotal, $igvTotal] = $this->convertirLineasDeMoneda(
                        $lineas,
                        $subtotalTotal,
                        $igvTotal,
                        $infoMoneda['moneda_original'],
                        $infoMoneda['moneda_facturacion'],
                        $infoMoneda['tipo_cambio_conversion']
                    );
                }

                $total = round($subtotalTotal + $igvTotal, 2);

                // Análisis de impuestos (28-ago-2026) — antes venía todo
                // hardcodeado a gravadas=subtotalTotal/exoneradas=0/
                // inafectas=0 y destino='nacional' literal. detectarMezclaTributaria()
                // ya garantiza que todos los itemsBajoLock comparten el
                // mismo destino_tributario, así que alcanza con resolverlo
                // de cualquiera de ellos (hoy siempre 'nacional', porque es
                // lo único que pasa el guardia — ver clase, pendiente
                // Amazonía/extranjero). tip_afe_igv SÍ puede variar por
                // línea (ej. Apéndice II) aunque el destino sea homogéneo.
                $montoGravadas = 0.0;
                $montoExoneradas = 0.0;
                $montoInafectas = 0.0;
                foreach ($lineas as $linea) {
                    match ($linea['tip_afe_igv']) {
                        '20' => $montoExoneradas += $linea['subtotal'],
                        '30' => $montoInafectas += $linea['subtotal'],
                        default => $montoGravadas += $linea['subtotal'],
                    };
                }

                $destinoTributarioVenta = $itemsBajoLock->isNotEmpty()
                    ? self::resolverDestinoTributario($itemsBajoLock->first())
                    : self::DESTINO_TRIBUTARIO_DEFAULT;

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
                    'currency' => $infoMoneda['moneda_facturacion'],
                    'moneda_original_cotizacion' => $infoMoneda['moneda_facturacion'] !== $infoMoneda['moneda_original']
                        ? $infoMoneda['moneda_original']
                        : null,
                    'tipo_cambio_override_moneda' => $infoMoneda['tipo_cambio_conversion'],
                    'motivo_override_moneda' => $infoMoneda['motivo_cambio_moneda'],
                    'is_exportacion' => $destinoTributarioVenta === 'extranjero' ? 1 : 0,
                    'destino' => $destinoTributarioVenta === 'amazonia' ? 'amazonia' : 'nacional',
                    'type_payment' => 1,
                    'condicion_pago' => 'contado',
                    'subtotal' => $subtotalTotal,
                    'igv' => $igvTotal,
                    'total' => $total,
                    'discount' => 0,
                    'discount_global' => 0,
                    'mto_oper_gravadas' => round($montoGravadas, 2),
                    'mto_oper_exoneradas' => round($montoExoneradas, 2),
                    'mto_oper_inafectas' => round($montoInafectas, 2),
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
                        'tip_afe_igv' => $linea['tip_afe_igv'],
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

                // ── Aplicar anticipos de la reserva (Tier 0, conexión
                // Adelantos↔Reservas, hallazgo de auditoría 2026-08-21) ──
                // Sin selección manual ("Facturar" simple): auto-aplica los
                // anticipos disponibles de la reserva hasta cubrir el total
                // de ESTA sub-factura, sin pasarse — un anticipo puede
                // quedar parcialmente consumido, el resto sigue disponible
                // para una sub-factura futura de la misma reserva. Con
                // selección manual ("Facturación especial"): un anticipo es
                // de la reserva completa, no de un pasajero puntual — no se
                // reparte automáticamente entre sub-facturas sin adivinar
                // (mismo criterio ya usado con ítems compartidos), el
                // vendedor elige a mano cuáles y cuánto.
                // Un anticipo solo puede aplicarse a una sub-factura del
                // MISMO cliente que lo pagó (AdvanceApplicationService ya
                // lo exige — un adelanto no cruza clientes, ver Tier 1 de
                // Adelantos). Con "Facturación múltiple por grupo de
                // pasajeros" el $cliente de esta sub-factura puede ser
                // distinto del cliente de la cotización (empresa A/empresa
                // B/el propio pasajero) — filtrar acá por client_id evita
                // ofrecer/auto-aplicar un anticipo que de todos modos el
                // servicio rechazaría, con un error más claro.
                $anticiposDelCliente = $reserva->anticipos()->with('advance')
                    ->get()
                    ->filter(fn (ReservaAnticipo $ra) => (int) $ra->advance->client_id === (int) $cliente->id)
                    // Bug real de auditoría (2026-09-24): con el override de
                    // moneda al facturar (ver resolverMonedaFacturacion()),
                    // esta Sale puede terminar en una moneda distinta a la
                    // del anticipo — sin este filtro, el auto-aplicado de
                    // abajo mezclaba montos de 2 monedas sin convertir, y
                    // AdvanceApplicationService::aplicar() rechazaba con 422
                    // ("adelanto en USD, venta en PEN") abortando TODA la
                    // transacción por un anticipo que el vendedor ni pidió
                    // usar. El anticipo en la otra moneda simplemente queda
                    // disponible para una futura sub-factura en SU moneda.
                    ->filter(fn (ReservaAnticipo $ra) => $ra->advance->currency === $venta->currency)
                    ->values();

                if (!empty($aplicacionesAdelantoSolicitadas)) {
                    $advanceIdsPermitidos = $anticiposDelCliente->pluck('advance_id')->all();

                    $sumaSolicitada = 0;
                    foreach ($aplicacionesAdelantoSolicitadas as $aplicacion) {
                        if (!in_array($aplicacion['advance_id'], $advanceIdsPermitidos, true)) {
                            throw new HttpException(
                                422,
                                "El adelanto #{$aplicacion['advance_id']} no está asociado a esta reserva " .
                                "para el cliente de esta factura (#{$cliente->id})."
                            );
                        }
                        $sumaSolicitada += (float) $aplicacion['amount'];
                    }

                    if (round($sumaSolicitada, 2) > round($total, 2)) {
                        throw new HttpException(
                            422,
                            "La suma de anticipos aplicados (S/ " . number_format($sumaSolicitada, 2) . ") " .
                            "supera el total de esta factura (S/ " . number_format($total, 2) . ")."
                        );
                    }

                    $aplicacionesAdelanto = $aplicacionesAdelantoSolicitadas;
                } else {
                    $restante = $total;
                    $aplicacionesAdelanto = [];

                    foreach ($anticiposDelCliente as $reservaAnticipo) {
                        if ($restante <= 0) {
                            break;
                        }

                        $disponible = $reservaAnticipo->advance->availableBalance();
                        if ($disponible <= 0) {
                            continue;
                        }

                        $monto = round(min($disponible, $restante), 2);
                        $aplicacionesAdelanto[] = ['advance_id' => $reservaAnticipo->advance_id, 'amount' => $monto];
                        $restante = round($restante - $monto, 2);
                    }
                }

                $totalAplicadoAdelantos = $this->advanceApplicationService->aplicar($venta, $aplicacionesAdelanto);

                if ($totalAplicadoAdelantos > 0) {
                    // Mismo criterio que SaleController::store(): solo el
                    // total/monto a pagar se reduce, no mto_oper_gravadas/
                    // igv/valor_venta (deben reflejar el valor íntegro del
                    // servicio) — enviarSunat() recalcula esto de forma
                    // independiente desde sale_details al emitir.
                    $nuevoTotal = round($venta->total - $totalAplicadoAdelantos, 2);

                    $venta->update([
                        'total' => $nuevoTotal,
                        'mto_imp_venta' => round($venta->mto_imp_venta - $totalAplicadoAdelantos, 2),
                        'debt' => $nuevoTotal,
                        'paid_out' => $totalAplicadoAdelantos,
                        'saldo_pendiente' => $nuevoTotal,
                        'state_payment' => $nuevoTotal <= 0 ? 3 : 1,
                    ]);
                }

                // reserva_pasajero_ids: el subconjunto que el vendedor
                // ELIGIÓ facturar en este Sale — ya NO todos los pasajeros
                // de la reserva (eso asumía la Fase A original, un solo
                // responsable de pago). Un pasajero queda "facturado" en
                // cuanto aparece acá, sin importar si le tocaban ítems sin
                // asignar que terminaron yendo a otro Sale.
                ReservaVenta::create([
                    'reserva_id' => $reserva->id,
                    'sale_id' => $venta->id,
                    'reserva_item_ids' => $reservaItemIdsFacturados,
                    'reserva_pasajero_ids' => $pasajeroIdsSolicitados,
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
            'pasajeros_facturados' => count($pasajeroIdsSolicitados),
        ]);
    }

    // Resuelve, valida y arma el conjunto final de reserva_items a
    // facturar a partir de pasajero_ids + reserva_item_ids_manual —
    // compartido por prepararFactura() (preview) y store() (real). Sobre
    // error devuelve un JsonResponse 422 listo para retornar tal cual;
    // sobre éxito devuelve ['items' => Collection<ReservaItem> (con
    // relaciones cargadas para agrupar/calcular), 'contexto' => array
    // (campos informativos para la respuesta del preview)].
    private function resolverSeleccion(Reserva $reserva, array $pasajeroIdsSolicitados, array $idsManualSolicitados)
    {
        $pasajerosAjenos = collect($pasajeroIdsSolicitados)->diff($reserva->pasajeros->pluck('id'))->values();
        if ($pasajerosAjenos->isNotEmpty()) {
            return response()->json([
                'code' => 422,
                'message' => 'Todos los pasajero_ids deben pertenecer a esta reserva.',
            ], 422);
        }

        $pasajerosYaFacturados = $this->pasajerosYaFacturadosIds($reserva);
        $pasajerosRepetidos = collect($pasajeroIdsSolicitados)->intersect($pasajerosYaFacturados)->values();

        $idsItemsYaFacturados = $this->itemsYaFacturadosIds($reserva);

        $todosLosItems = ReservaItem::with([
            'pasajeros',
            'proveedorTarifa',
            'alternativaItem.proveedorTarifa.proveedorServicio.destinoServicio.servicio',
            // Análisis de impuestos (28-ago-2026) — resolverTipAfeIgvItem()
            // necesita esto para el caso pasaje_aereo (única fuente más
            // específica que el campo genérico de alternativa_items).
            'alternativaItem.cotizacionPasajeAereo',
        ])->where('reserva_id', $reserva->id)->whereNotIn('id', $idsItemsYaFacturados)->get();

        $itemsAuto = collect();
        $pendientesPorFaltante = [];
        $itemsSinAsignar = collect();

        foreach ($todosLosItems as $item) {
            $pasajerosVinculados = $item->pasajeros->pluck('id');

            if ($pasajerosVinculados->isEmpty()) {
                $itemsSinAsignar->push($item);
                continue;
            }

            // Ítem "huérfano" (hallazgo de auditoría 2026-09-24): TODOS sus
            // pasajeros vinculados ya están facturados en otra venta de esta
            // reserva — típicamente porque una Nota de Crédito parcial
            // liberó este ítem puntual (NotaElectronicaController, caso
            // tipo_afectacion='parcial') sin tocar reserva_pasajero_ids
            // (el pasajero sigue legítimamente cubierto por el resto de esa
            // venta). Sin esto, el ítem no calificaba ni para itemsAuto (su
            // pasajero ya está bloqueado por pasajerosRepetidos más abajo)
            // ni para itemsSinAsignar (sí tiene pasajero vinculado) —
            // quedaba invisible para siempre, sin ningún camino de
            // re-facturación por la API. Se ofrece acá como "sin asignar"
            // para que el vendedor lo elija a mano vía
            // reserva_item_ids_manual, reusando el mismo mecanismo ya
            // probado que "Facturar simple" usa para arrastrar ítems
            // sueltos reenviando un pasajero_id ya facturado (ver
            // pasajeroIdsParaFacturarSimple() en el frontend).
            if ($pasajerosVinculados->diff($pasajerosYaFacturados)->isEmpty()) {
                $itemsSinAsignar->push($item);
                continue;
            }

            $faltantes = $pasajerosVinculados->diff($pasajeroIdsSolicitados)->values();
            if ($faltantes->isEmpty()) {
                $itemsAuto->push($item);
                continue;
            }

            // Parcialmente cubierto: hay overlap real con la selección
            // actual, pero falta al menos un pasajero vinculado — se
            // informa el motivo en vez de incluirlo a medias (nunca se
            // fragmenta un ítem compartido entre Sales distintos).
            if ($pasajerosVinculados->intersect($pasajeroIdsSolicitados)->isNotEmpty()) {
                $pendientesPorFaltante[] = [
                    'reserva_item_id' => $item->id,
                    'nombre' => ReservaController::resolverNombreItem($item->alternativaItem),
                    'pasajeros_faltantes' => $faltantes->all(),
                ];
            }
        }

        $idsManualValidos = $itemsSinAsignar->pluck('id');
        $idsManualAjenos = collect($idsManualSolicitados)->diff($idsManualValidos)->values();
        if ($idsManualAjenos->isNotEmpty()) {
            return response()->json([
                'code' => 422,
                'message' => 'Los siguientes reserva_item_ids no están disponibles como "ítem sin asignar" de esta '
                    . 'reserva (ya facturados, con pasajero vinculado, o no pertenecen a la reserva): '
                    . $idsManualAjenos->implode(', '),
            ], 422);
        }

        $itemsManual = $itemsSinAsignar->whereIn('id', $idsManualSolicitados)->values();
        if ($pasajerosRepetidos->isNotEmpty()) {
            $todosLosPasajerosYaFacturados = $pasajerosRepetidos->count() === count($pasajeroIdsSolicitados);
            if (! $todosLosPasajerosYaFacturados || $itemsManual->isEmpty()) {
                return response()->json([
                    'code' => 422,
                    'message' => 'Los siguientes pasajeros ya fueron facturados en otra venta de esta reserva: ' . $pasajerosRepetidos->implode(', '),
                ], 422);
            }
        }
        // Sin guard de "vacío" acá a propósito: prepararFactura() (preview)
        // necesita poder devolver 200 con 0 ítems (ej. recién se seleccionó
        // un pasajero sin nada auto-incluido todavía, antes de que el
        // vendedor marque algo del pool "sin asignar") — bloquear acá haría
        // que el preview fallara apenas se abre el modal. store() sí
        // bloquea explícitamente con 422 antes de intentar crear un Sale
        // vacío, ver más abajo.
        $itemsFinal = $pasajerosRepetidos->isNotEmpty()
            ? $itemsManual
            : $itemsAuto->concat($itemsManual)->unique('id')->values();

        $pasajerosPendientes = $reserva->pasajeros->pluck('id')
            ->diff($pasajerosYaFacturados)
            ->diff($pasajeroIdsSolicitados)
            ->values();

        // Nota: incluye TODOS los ítems sin asignar (ya facturados excluidos
        // arriba), sin importar si ya están marcados en
        // reserva_item_ids_manual — así el checkbox del frontend puede
        // reflejar su estado actual sin que el ítem desaparezca de la
        // lista al tildarlo (mismo criterio ya usado por
        // items_pendientes_por_pasajero_faltante, que tampoco depende de
        // la selección).
        $contexto = [
            'pasajeros_incluidos' => $pasajeroIdsSolicitados,
            'pasajeros_pendientes' => $pasajerosPendientes,
            'items_pendientes_por_pasajero_faltante' => $pendientesPorFaltante,
            'items_sin_asignar_disponibles' => $itemsSinAsignar->map(fn (ReservaItem $it) => [
                'reserva_item_id' => $it->id,
                'nombre' => ReservaController::resolverNombreItem($it->alternativaItem),
                'total' => (float) $it->alternativaItem->total_convertido,
            ])->values(),
        ];

        // Sugerencia de cliente (no vinculante): si se seleccionó un solo
        // pasajero y ese pasajero ya tiene perfil de cliente propio
        // (pasajero_catalogo.cliente_id, §6.5 del plan), se lo ofrece
        // como punto de partida — el vendedor puede elegir cualquier otro
        // cliente igual, esto solo evita re-escribir un buscador si el
        // pasajero ya es cliente conocido.
        if (count($pasajeroIdsSolicitados) === 1) {
            $pasajero = $reserva->pasajeros->firstWhere('id', $pasajeroIdsSolicitados[0]);
            $clienteSugerido = $pasajero?->pasajeroCatalogo?->cliente;
            if ($clienteSugerido) {
                $contexto['cliente_sugerido'] = [
                    'id' => $clienteSugerido->id,
                    'full_name' => $clienteSugerido->full_name,
                    'n_document' => $clienteSugerido->n_document,
                ];
            }
        }

        return ['items' => $itemsFinal, 'contexto' => $contexto];
    }

    // Ningún reserva_item pedido puede estar ya cubierto por una
    // ReservaVenta existente de esta reserva — compartido por
    // resolverSeleccion() y usado también dentro de ella.
    private function itemsYaFacturadosIds(Reserva $reserva): Collection
    {
        return $reserva->ventas
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_item_ids ?? [])
            ->unique()
            ->values();
    }

    // Un pasajero ya facturado (apareció en el reserva_pasajero_ids de
    // ALGUNA ReservaVenta previa) no puede volver a seleccionarse — cada
    // pasajero pertenece a un único Sale por diseño (un responsable de
    // pago, un comprobante). Ver docblock de la clase para el trade-off
    // de ítems compartidos que esto implica.
    private function pasajerosYaFacturadosIds(Reserva $reserva): Collection
    {
        return $reserva->ventas
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_pasajero_ids ?? [])
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
    // Análisis de impuestos (28-ago-2026): destino_tributario/tip_afe_igv
    // ahora también viven como columna propia de reserva_item (copiada de
    // alternativa_item al crear la reserva — ver ReservaController::
    // crearReservaItemDesdeAlternativaItem()), cubriendo los 4 orígenes que
    // antes no tenían de dónde leerlo (mayorista/pasaje_aereo/manual/guia).
    // proveedorTarifa queda como fallback de compatibilidad para
    // reserva_items creados ANTES de este fix (columna null) — nunca se
    // retrofitea data histórica, ver plan de impuestos.
    // public static (2026-09-23): sin estado de instancia, pura sobre
    // $item — ReservaController la reusa para mostrar el tratamiento
    // tributario por servicio en el tab "Servicios" (badge nuevo, hallazgo
    // de auditoría: hoy solo se descubre una mezcla al chocar con el
    // bloqueo de facturar). Una sola fuente de verdad, nunca duplicar esta
    // cascada en otro controller.
    public static function resolverDestinoTributario(ReservaItem $item): string
    {
        return $item->destino_tributario
            ?? $item->proveedorTarifa?->destino_tributario
            ?? self::DESTINO_TRIBUTARIO_DEFAULT;
    }

    // Facturar en una moneda distinta a la de la cotización de origen
    // (2026-09-24 — caso real: cotización en USD, cliente pide el
    // comprobante en soles). Versión ESTRICTA, usada por store(): si se
    // pide una moneda distinta a la de la cotización, exige tipo de cambio
    // + motivo explícitos en el request (mismo criterio de auditoría que
    // motivo_override_tributario en reserva_items) — nunca se aplica un
    // tipo de cambio "sugerido" sin que el vendedor lo haya confirmado
    // (aunque coincida con la sugerencia, confirmar es parte del flujo).
    // Devuelve un JsonResponse 422 listo para retornar tal cual si falta
    // algo, o el array resuelto en éxito.
    private function resolverMonedaFacturacion(Request $request, Reserva $reserva)
    {
        $monedaOriginal = $reserva->alternativa->moneda_cotizacion;
        $monedaFacturacion = $request->filled('moneda_facturacion') ? $request->input('moneda_facturacion') : $monedaOriginal;

        if ($monedaFacturacion === $monedaOriginal) {
            return [
                'moneda_original' => $monedaOriginal,
                'moneda_facturacion' => $monedaFacturacion,
                'tipo_cambio_conversion' => null,
                'motivo_cambio_moneda' => null,
            ];
        }

        if (! $request->filled('tipo_cambio_conversion') || ! $request->filled('motivo_cambio_moneda')) {
            return response()->json([
                'code' => 422,
                'message' => "Para facturar en una moneda distinta a la de la cotización ({$monedaOriginal}) hace " .
                    'falta indicar el tipo de cambio aplicado y el motivo.',
            ], 422);
        }

        return [
            'moneda_original' => $monedaOriginal,
            'moneda_facturacion' => $monedaFacturacion,
            'tipo_cambio_conversion' => (float) $request->input('tipo_cambio_conversion'),
            'motivo_cambio_moneda' => $request->input('motivo_cambio_moneda'),
        ];
    }

    // Misma decisión de negocio que resolverMonedaFacturacion(), versión
    // LENIENTE para prepararFactura() (preview de solo lectura, nunca
    // persiste nada): si se pide otra moneda pero todavía no hay tipo de
    // cambio confirmado por el vendedor, sugiere el del día vía
    // TipoCambioSunatResolver (venta) para que el frontend lo precargue
    // editable — sin bloquear el preview si no hay dato disponible (mismo
    // criterio de "no bloquea" que el propio resolver documenta).
    private function resolverMonedaFacturacionPreview(Request $request, Reserva $reserva): array
    {
        $monedaOriginal = $reserva->alternativa->moneda_cotizacion;
        $monedaFacturacion = $request->filled('moneda_facturacion') ? $request->input('moneda_facturacion') : $monedaOriginal;

        if ($monedaFacturacion === $monedaOriginal) {
            return [
                'moneda_original' => $monedaOriginal,
                'moneda_facturacion' => $monedaFacturacion,
                'tipo_cambio' => null,
                'tipo_cambio_sugerido' => null,
            ];
        }

        $tipoCambioSugerido = $this->tipoCambioSunatResolver->resolverParaFecha(now())?->venta;
        $tipoCambio = $request->filled('tipo_cambio_conversion')
            ? (float) $request->input('tipo_cambio_conversion')
            : $tipoCambioSugerido;

        return [
            'moneda_original' => $monedaOriginal,
            'moneda_facturacion' => $monedaFacturacion,
            'tipo_cambio' => $tipoCambio !== null ? (float) $tipoCambio : null,
            'tipo_cambio_sugerido' => $tipoCambioSugerido !== null ? (float) $tipoCambioSugerido : null,
        ];
    }

    // Serie visible en el preview (2026-09-24, pedido del usuario: "debe
    // aparecer de manera visible la serie" — antes solo se veía la
    // sucursal, el vendedor no sabía si iba a salir F001, F002, etc. hasta
    // confirmar). Solo lectura: resolverParaUsuario() no reserva ningún
    // correlativo, solo lee la fila de serie_comprobantes ya existente.
    // null si todavía no hay suficiente info (sin tipo_comprobante_codigo
    // elegido) o si no hay ninguna serie activa configurada para esa
    // combinación — nunca rompe el preview, que siempre debe responder 200.
    private function resolverSeriePreview(Request $request, array $validado, string $monedaFacturacion): ?string
    {
        if (empty($validado['tipo_comprobante_codigo'])) {
            return null;
        }

        $usuario = auth('api')->user();
        if (! $usuario) {
            return null;
        }

        $branchIdSolicitado = ($usuario->can('can_switch_branch') && $request->filled('branch_id'))
            ? (int) $request->branch_id
            : null;

        try {
            $serieResuelta = $this->serieComprobanteService->resolverParaUsuario(
                $usuario,
                $validado['tipo_comprobante_codigo'],
                $monedaFacturacion,
                $branchIdSolicitado
            );

            return $serieResuelta['serie']->serie;
        } catch (HttpException $e) {
            return null;
        }
    }

    // Aplica la conversión de PriceEngineService::convertirMoneda() (mismo
    // criterio ×/÷ ya usado al armar cotizaciones: USD→PEN multiplica,
    // PEN→USD divide) a cada línea ya calculada por construirLineas() —
    // se aplica DESPUÉS de construirLineas() a propósito, nunca dentro,
    // para no acoplar el cálculo puro de líneas (compartido con el flujo
    // normal sin conversión) a esta decisión de negocio nueva.
    private function convertirLineasDeMoneda(
        array $lineas,
        float $subtotalTotal,
        float $igvTotal,
        string $monedaOrigen,
        string $monedaDestino,
        float $tipoCambio
    ): array {
        foreach ($lineas as &$linea) {
            $linea['subtotal'] = $this->priceEngineService->convertirMoneda($linea['subtotal'], $monedaOrigen, $monedaDestino, $tipoCambio);
            $linea['igv'] = $this->priceEngineService->convertirMoneda($linea['igv'], $monedaOrigen, $monedaDestino, $tipoCambio);
            $linea['precio_final'] = $this->priceEngineService->convertirMoneda($linea['precio_final'], $monedaOrigen, $monedaDestino, $tipoCambio);
        }
        unset($linea);

        // Bug real de auditoría (2026-09-24): convertir $subtotalTotal/
        // $igvTotal por separado (en vez de sumar las líneas YA
        // convertidas y redondeadas una por una) podía desalinear el
        // header de la Sale por ±0.01 respecto a la suma real de sus
        // SaleDetail — sumar N líneas redondeadas independientemente no
        // siempre da lo mismo que redondear la suma. Mismo criterio que ya
        // usa construirLineas() (acumula durante el loop, redondea una
        // sola vez al final) — acá se recalcula desde las líneas ya
        // convertidas en vez de re-convertir el agregado original.
        $subtotalTotal = round(collect($lineas)->sum('subtotal'), 2);
        $igvTotal = round(collect($lineas)->sum('igv'), 2);

        return [$lineas, $subtotalTotal, $igvTotal];
    }

    // Mismo criterio de cascada que resolverDestinoTributario(), un nivel
    // más fino (tip_afe_igv puede variar dentro de un mismo destino
    // homogéneo — ej. dos ítems 'nacional' donde uno está exonerado por
    // Apéndice II sin relación con Amazonía). pasaje_aereo es la única
    // excepción: cotizacion_pasaje_aereo.tip_afe_igv (solo sobre
    // fee_agencia_monto, ver docblock de CotizacionPasajeAereo) es más
    // específico que el campo genérico de alternativa_items y tiene
    // prioridad cuando está presente. '10' es el mismo fallback legado de
    // siempre para reserva_items de antes de este fix sin ningún dato.
    // public static — mismo motivo que resolverDestinoTributario() arriba.
    public static function resolverTipAfeIgvItem(ReservaItem $item): string
    {
        $alternativaItem = $item->alternativaItem;

        if ($alternativaItem?->origen_tipo === AlternativaItem::ORIGEN_PASAJE_AEREO
            && $alternativaItem->cotizacionPasajeAereo?->tip_afe_igv !== null) {
            return $alternativaItem->cotizacionPasajeAereo->tip_afe_igv;
        }

        return $item->tip_afe_igv
            ?? $item->proveedorTarifa?->tip_afe_igv
            ?? '10';
    }

    // Guardia tributario: null si todos los ítems comparten el mismo
    // destino_tributario efectivo (caso normal, la mayoría de las
    // reservas reales); si no, la estructura de bloqueo lista para la
    // respuesta HTTP (sin el wrapper code/message, cada caller decide
    // status). Se evalúa siempre sobre el subconjunto final a facturar en
    // ESTE Sale — dos pasajeros distintos pueden terminar en Sales con
    // distinto destino_tributario cada uno, mientras cada Sale individual
    // sea homogéneo.
    // Hallazgo de auditoría (2026-09-23) + pedido del usuario (2026-09-24,
    // "debo poder ver lo que he seleccionado si fue gravado o exonerado"):
    // antes esto solo se calculaba/devolvía cuando el bloqueo tributario ya
    // había disparado — antes de eso, el vendedor elegía pasajeros/ítems a
    // ciegas sin saber el tratamiento hasta chocar con el error. Un solo
    // mapeo, usado tanto por detectarMezclaTributaria() (bloqueado) como
    // por prepararFactura() en el camino OK (siempre visible).
    private function mapearItemsPorTratamientoTributario(Collection $items): Collection
    {
        return $items->map(fn (ReservaItem $it) => [
            'reserva_item_id' => $it->id,
            'nombre' => ReservaController::resolverNombreItem($it->alternativaItem, $it),
            'destino_tributario' => self::resolverDestinoTributario($it),
            'tip_afe_igv' => self::resolverTipAfeIgvItem($it),
        ])->values();
    }

    private function detectarMezclaTributaria(Collection $items): ?array
    {
        $destinos = $items->map(fn (ReservaItem $it) => self::resolverDestinoTributario($it))->unique()->values();
        $itemsPorDestino = $this->mapearItemsPorTratamientoTributario($items);

        if ($destinos->count() > 1) {
            return [
                'bloqueado_tributario' => true,
                'motivo' => self::MENSAJE_MEZCLA_TRIBUTARIA,
                'destinos_tributarios_detectados' => $destinos->all(),
                'items_por_destino_tributario' => $itemsPorDestino,
            ];
        }

        $destinoUnico = $destinos->first();

        // 'extranjero' sigue bloqueado sin excepción (ver constante).
        if ($destinoUnico === 'extranjero') {
            return [
                'bloqueado_tributario' => true,
                'motivo' => self::MENSAJE_TRATAMIENTO_TRIBUTARIO_EXTRANJERO,
                'destinos_tributarios_detectados' => $destinos->all(),
                'items_por_destino_tributario' => $itemsPorDestino,
            ];
        }

        // 'amazonia' se puede facturar SOLO si cada ítem ya fue confirmado
        // a mano (motivo_override_tributario no nulo) — ver constante
        // MENSAJE_AMAZONIA_SIN_CONFIRMAR arriba para el porqué.
        if ($destinoUnico === 'amazonia') {
            $sinConfirmar = $items->filter(fn (ReservaItem $it) => $it->motivo_override_tributario === null)->values();

            if ($sinConfirmar->isNotEmpty()) {
                return [
                    'bloqueado_tributario' => true,
                    'motivo' => self::MENSAJE_AMAZONIA_SIN_CONFIRMAR,
                    'destinos_tributarios_detectados' => $destinos->all(),
                    'items_por_destino_tributario' => $itemsPorDestino,
                    'items_sin_confirmar_ids' => $sinConfirmar->pluck('id')->values(),
                ];
            }
        }

        return null;
    }

    // Agrupa reserva_items por categoría (HOTEL/TRANSPORTE/TOUR/VUELO/
    // OTROS vía clasificarCategoria()), preservando ese orden para que las
    // líneas del comprobante/preview salgan estables — no en el orden
    // arbitrario en que llegaron los ids. Descarta categorías vacías.
    //
    // Análisis de impuestos (28-ago-2026): dentro de cada categoría,
    // sub-agrupa además por tip_afe_igv real (resolverTipAfeIgvItem()) —
    // dos ítems de la misma categoría pueden tener tratamiento tributario
    // distinto (ej. dos hoteles nacionales, uno exonerado por Apéndice II)
    // y fusionarlos en una sola línea de SaleDetail perdería esa
    // diferencia. detectarMezclaTributaria() ya garantiza que el DESTINO es
    // homogéneo en todo el Sale — esto es un nivel más fino, dentro de ese
    // mismo destino. Devuelve una lista plana (ya no un array keyed por
    // categoría — puede haber más de un grupo por categoría ahora), cada
    // elemento con 'categoria'/'tip_afe_igv'/'items'.
    private function agruparPorCategoria(Collection $items): array
    {
        $porCategoria = [];
        foreach (array_keys(self::SKU_POR_CATEGORIA) as $categoria) {
            $porCategoria[$categoria] = collect();
        }
        foreach ($items as $item) {
            $porCategoria[$this->clasificarCategoria($item)]->push($item);
        }

        $grupos = [];
        foreach ($porCategoria as $categoria => $itemsCategoria) {
            if ($itemsCategoria->isEmpty()) {
                continue;
            }

            foreach ($itemsCategoria->groupBy(fn (ReservaItem $it) => self::resolverTipAfeIgvItem($it)) as $tipAfeIgv => $itemsGrupo) {
                // (string) explícito: PHP castea claves de array que parecen
                // enteros decimales ('10'/'20'/'30') a int — groupBy() usa un
                // array por debajo, así que $tipAfeIgv llega acá como int(10),
                // no string('10'), y rompería el === estricto de más abajo
                // (construirLineas()) y el match() de store(). Bug real
                // encontrado por el test de regresión de este mismo fix.
                $grupos[] = [
                    'categoria' => $categoria,
                    'tip_afe_igv' => (string) $tipAfeIgv,
                    'items' => $itemsGrupo->values(),
                ];
            }
        }

        return $grupos;
    }

    // Cálculo puro (sin tocar BD) de las líneas del comprobante a partir
    // de los grupos ya armados — compartido por el preview de solo lectura
    // (prepararFactura) y la creación real (store, dentro de su
    // transacción). $textoPersonalizado, si viene, reemplaza la
    // descripcion_detalle autogenerada en TODAS las líneas de este Sale
    // (confirmado con el usuario: un texto por Sale completo, no por
    // línea individual). Devuelve [lineas, subtotalTotal, igvTotal].
    private function construirLineas(array $gruposPorCategoria, Collection $productosPlaceholder, ?string $textoPersonalizado = null): array
    {
        $subtotalTotal = 0;
        $igvTotal = 0;
        $lineas = [];

        foreach ($gruposPorCategoria as $grupo) {
            $categoria = $grupo['categoria'];
            $tipAfeIgv = $grupo['tip_afe_igv'];
            $items = $grupo['items'];
            $producto = $productosPlaceholder->get(self::SKU_POR_CATEGORIA[$categoria]);

            // '10' gravado → 18%; '20' exonerado/'30' inafecto → 0%. Ya no
            // se lee tip_afe_igv_default del producto placeholder (análisis
            // de impuestos, 28-ago-2026) — el valor real viene del ítem.
            $porcentajeIgv = $tipAfeIgv === '10' ? 18.0 : 0.0;

            $precioFinalLinea = round($items->sum(fn (ReservaItem $it) => (float) $it->alternativaItem->total_convertido), 2);
            $subtotalLinea = round($precioFinalLinea / (1 + $porcentajeIgv / 100), 2);
            $igvLinea = round($precioFinalLinea - $subtotalLinea, 2);

            $descripcionDetalle = $textoPersonalizado ?? $items->map(fn (ReservaItem $it) => sprintf(
                '%s (%s)',
                ReservaController::resolverNombreItem($it->alternativaItem),
                $it->fecha?->toDateString() ?? 'sin fecha'
            ))->implode('; ');

            $subtotalTotal += $subtotalLinea;
            $igvTotal += $igvLinea;

            $lineas[] = [
                'categoria' => $categoria,
                'producto' => $producto,
                'grupo' => $items,
                'tip_afe_igv' => $tipAfeIgv,
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
