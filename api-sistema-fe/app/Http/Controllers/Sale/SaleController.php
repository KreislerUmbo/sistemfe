<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Http\Resources\Client\ClientCollection;
use App\Http\Resources\Product\ProductCollection;
use App\Http\Resources\Sale\SaleCollection;
use App\Http\Resources\Sale\SaleResource;
use App\Models\Client\Client;
use App\Models\Company;
use App\Models\Product\Product;
use App\Models\Product\TaxConfig;
use App\Models\Product\DetractionCode;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Models\Sale\SalePayment;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaleController extends Controller
{
    // ── Blindaje ISC: el backend es la fuente de verdad, no el frontend ──
    // El cálculo de ISC ocurre en el frontend (sale/register.vue) y aquí
    // solo se persistía tal cual llegaba. Si el producto tiene datos
    // residuales de ISC (bug conocido: is_isc=false pero percentage_isc o
    // monto_isc_fijo > 0) o el payload fue manipulado, el ISC se colaba en
    // la venta y en el comprobante SUNAT. Aquí se verifica is_isc del
    // producto real en BD y, si es false, se tratan percentage_isc,
    // monto_isc_fijo y tipo_isc como huérfanos (se descartan) sin importar
    // qué haya mandado el cliente.
    private function sanitizeIscForDetail(array $campos_detalle, int $product_id): array
    {
        $producto = Product::find($product_id);

        if (!$producto || !$producto->is_isc) {
            $isc_removido = (float) ($campos_detalle['isc'] ?? 0);

            $campos_detalle['percentage_isc'] = 0;
            $campos_detalle['isc'] = 0;
            $campos_detalle['tipo_isc'] = null;
            $campos_detalle['monto_isc_fijo'] = 0;

            // Mantener consistente el total de impuestos de la línea
            // (igv + isc + icbper) tras descartar el ISC indebido.
            if ($isc_removido > 0 && isset($campos_detalle['total_impuestos'])) {
                $campos_detalle['total_impuestos'] = max(0, $campos_detalle['total_impuestos'] - $isc_removido);
            }
        }

        return $campos_detalle;
    }

    // ── Blindaje regímenes especiales: retención/detracción/percepción son ──
    // incompatibles con operaciones exoneradas, inafectas o de exportación.
    // SUNAT no permite aplicar estos regímenes sobre ese tipo de operaciones
    // (Res. de Superintendencia del Régimen de Retenciones, art. 7.2).
    // retencion_igv es un SELECTOR ÚNICO: 0=ninguno, 1=retención, 2=detracción, 3=percepción.
    // Se valida aquí, antes de abrir transacción, para no dejar ventas
    // inconsistentes que luego fallen (o peor, se envíen mal) a Greenter/SUNAT.
    private function validarRegimenEspecial(Request $request): void
    {
        $retencion_igv = (int) $request->retencion_igv;

        if ($retencion_igv === 0) {
            return; // no se aplicó ningún régimen especial, nada que validar
        }

        $tiene_exoneracion = (float) ($request->mto_oper_exoneradas ?? 0) > 0;
        $tiene_inafecto     = (float) ($request->mto_oper_inafectas ?? 0) > 0;
        $es_exportacion     = (int) ($request->is_exportacion ?? 0) === 1;

        if (!$tiene_exoneracion && !$tiene_inafecto && !$es_exportacion) {
            return; // operación gravada normal, el régimen especial es válido
        }

        $regimenes = [1 => 'Retención', 2 => 'Detracción', 3 => 'Percepción'];
        $nombre_regimen = $regimenes[$retencion_igv] ?? 'Régimen especial';

        $motivo = $es_exportacion
            ? 'una operación de exportación'
            : ($tiene_exoneracion ? 'una operación exonerada' : 'una operación inafecta');

        throw new HttpException(
            422,
            "No se puede aplicar {$nombre_regimen} sobre {$motivo}. SUNAT no permite " .
            "retención, detracción ni percepción del IGV en operaciones exoneradas, " .
            "inafectas o de exportación. Revisa la configuración tributaria de la venta."
        );
    }

    // ── Listado de ventas con filtros ────────────────────────────────
    public function index(Request $request)
    {
        $buscar_producto  = $request->search_product;
        $categorie_id     = $request->categorie_id;
        $buscar_id_venta  = $request->search;
        $buscar_cliente   = $request->search_client;
        $estado_venta     = $request->state_sale;
        $tipo_pago        = $request->type_payment;
        $fecha_inicio     = $request->start_date;
        $fecha_fin        = $request->end_date;

        $ventas = Sale::filterMultiple(
            $buscar_producto,
            $categorie_id,
            $buscar_id_venta,
            $buscar_cliente,
            $estado_venta,
            $tipo_pago,
            $fecha_inicio,
            $fecha_fin
        )
            // Notas ya emitidas por venta (eager load: evita N+1 al armar
            // el badge de notas en el listado, ver SaleResource).
            ->with("notes")
            ->orderBy("id", "desc")
            ->paginate(25);

        return response()->json([
            "total"    => $ventas->total(),
            "paginate" => 25,
            "sales"    => SaleCollection::make($ventas),
        ]);
    }

    // ── Configuración inicial del formulario de ventas ───────────────
    public function config()
    {
        $fecha_hoy = today();

        // Clientes activos ordenados por nombre para el selector
        $clientes = Client::where("state", 1)
            ->orderBy("full_name", "asc")
            ->get();

        // Productos activos (excluye los de nota especial)
        $productos = Product::where("state", 1)
            ->where("is_especial_nota", 0)
            ->orderBy("title", "asc")
            ->get();

        // Calcular el próximo número de transacción interno
        $ultimo_numero_transaccion = 1000;
        $ultima_venta = Sale::orderBy("id", "desc")->first();
        if ($ultima_venta) {
            $ultimo_numero_transaccion = $ultima_venta->n_transaction + 1;
        }

        // Parámetros tributarios desde tax_configs (tasas configuradas por el contador)
        // El frontend los usa para calcular IGV, detracción, percepción, etc.
        // No se usan valores hardcodeados en el código
        $parametros_tributarios = TaxConfig::where('es_activo', true)
            ->get(['nombre', 'tipo', 'tasa_porcentaje', 'codigo_sunat', 'ubigeos_aplicables'])
            ->groupBy('tipo');

        // Códigos de detracción activos para el selector del formulario
        $codigos_detraccion = DetractionCode::where('estado', true)
            ->orderBy('codigo')
            ->get(['codigo', 'anexo', 'nombre', 'tasa_porcentaje', 'monto_minimo']);

        return response()->json([
            "clients"                => ClientCollection::make($clientes),
            "products"               => ProductCollection::make($productos),
            "today"                  => $fecha_hoy->format("Y-m-d"),
            "n_transaction"          => str_pad($ultimo_numero_transaccion, 8, "0", STR_PAD_LEFT),
            // Agrupados por tipo: { "IGV": [...], "IVAP": [...], "ICBPER": [...], "ISC": [...] }
            "parametros_tributarios" => $parametros_tributarios,
            "codigos_detraccion"     => $codigos_detraccion,
        ]);
    }

    // ── Generar la representación impresa (PDF) de la venta ──────────
    // Solo accesible vía URL firmada temporal (middleware 'signed' en la
    // ruta) — ver pdfSignedUrl() para el endpoint autenticado que genera
    // esa URL. Soporta 2 formatos: 'a4' (factura/boleta normal) y
    // 'ticket80mm' (impresora térmica), seleccionable con ?format=.
    public function pdf(string $id, Request $request)
    {
        $venta = Sale::with(['client', 'user', 'sale_details.product', 'sale_details.product_categorie'])
            ->find($id);

        if (!$venta) {
            return abort(404);
        }

        $formato = $request->query('format');
        if (!in_array($formato, ['a4', 'ticket80mm'])) {
            $formato = $venta->user->formato_impresion_default ?? 'a4';
        }

        $empresa = Company::first();
        $qr      = app(QrCodeService::class)->generarQrComprobante($venta);
        $vista   = $formato === 'ticket80mm' ? 'pdf.ticket80mm' : 'pdf.a4';

        $pdf = Pdf::loadView($vista, compact('venta', 'empresa', 'qr'));

        if ($formato === 'ticket80mm') {
            // Ancho fijo 80mm (≈226.77pt). Dompdf no soporta alto "auto" real,
            // así que se estima el alto según cantidad de ítems.
            $alto_estimado = 480 + ($venta->sale_details->count() * 40);
            $pdf->setPaper([0, 0, 226.77, $alto_estimado], 'portrait');
        } else {
            // Papel A4 explícito — el default de Dompdf es "letter" (carta US),
            // y este comprobante es para el mercado peruano.
            $pdf->setPaper('a4', 'portrait');
        }

        return $pdf->stream('comprobante_' . $id . '.pdf');
    }

    // ── Generar URL firmada temporal para imprimir/ver el PDF ────────
    // Requiere estar autenticado (ruta dentro del grupo auth:api). Devuelve
    // una URL que incluye firma + expiración (10 min) para la ruta pública
    // 'sales.pdf' — así se puede compartir/abrir sin login pero sin exponer
    // los comprobantes a cualquiera que adivine el ID.
    public function pdfSignedUrl(Request $request, string $id)
    {
        $venta = Sale::with('user')->findOrFail($id);

        $formato = $request->query('format');
        if (!in_array($formato, ['a4', 'ticket80mm'])) {
            $formato = $venta->user->formato_impresion_default ?? 'a4';
        }

        $url = URL::temporarySignedRoute('sales.pdf', now()->addMinutes(10), [
            'id'     => $id,
            'format' => $formato,
        ]);

        return response()->json(['url' => $url]);
    }

    // ── Registrar nueva venta ────────────────────────────────────────
    public function store(Request $request)
    {
        $this->validarRegimenEspecial($request);

        $detalles_venta = $request->sale_details;
        $pagos_venta    = $request->payments;

        try {
            DB::beginTransaction();

            // ── Crear cabecera de la venta ────────────────────────────
            $venta = Sale::create([
                // Identificación
                "date"            => $request->date,
                "serie"           => $request->serie,       // F001 o B001
                "n_transaction"   => $request->n_transaction,
                // n_operacion NO se asigna aquí. El correlativo real solo existe
                // cuando la venta se envía a SUNAT (ver FacturacionElectronicaController::
                // enviarSunat() → reservarCorrelativo()). Asignarlo aquí producía
                // valores rotos tipo "F001-" para ventas aún no enviadas.

                // Relaciones
                "user_id"         => auth('api')->user()->id,
                "client_id"       => $request->client_id,
                "type_client"     => $request->type_client,

                // Tipo de documento del cliente (Catálogo 06 SUNAT)
                // Se copia aquí para preservarlo aunque el cliente cambie después
                "cod_tipo_doc_cliente" => $request->cod_tipo_doc_cliente,

                // Configuración de la operación
                "currency"        => $request->currency,      // 'PEN' o 'USD'
                "is_exportacion"  => $request->is_exportacion,
                "destino"         => $request->destino,       // 'amazonia' o 'nacional'
                "state_sale"      => $request->state_sale,    // 1=venta, 2=cotización
                "type_payment"    => $request->type_payment,  // 1=contado, 2=crédito

                // Totales calculados en el frontend
                "subtotal"        => $request->subtotal,
                "igv"             => $request->igv,
                "total"           => $request->total,
                "discount"        => $request->discount,
                "discount_global" => $request->discount_global,
                "igv_discount_general" => $request->igv_discount_general ?? 0,

                // Totales pre-calculados para Greenter (evita recalcularlos al emitir)
                "mto_oper_gravadas"    => $request->mto_oper_gravadas ?? 0,
                "mto_oper_exoneradas"  => $request->mto_oper_exoneradas ?? 0,
                "mto_oper_inafectas"   => $request->mto_oper_inafectas ?? 0,
                "mto_oper_exportacion" => $request->mto_oper_exportacion ?? 0,
                "mto_oper_gratuitas"   => $request->mto_oper_gratuitas ?? 0,

                // Impuestos desagregados por tipo
                "isc_total"        => $request->isc_total ?? 0,
                "icbper_total"     => $request->icbper_total ?? 0,
                "ivap_total"       => $request->ivap_total ?? 0,
                "total_impuestos"  => $request->total_impuestos ?? 0,

                // Totales finales del comprobante
                "valor_venta"      => $request->valor_venta ?? 0,
                "mto_imp_venta"    => $request->mto_imp_venta ?? 0,
                "redondeo"         => $request->redondeo ?? 0,

                // Regímenes especiales
                "retencion_igv"        => $request->retencion_igv,   // 0,1,2,3
                "monto_retencion"      => $request->monto_retencion ?? 0,
                "codigo_detraccion"    => $request->codigo_detraccion,
                "porcentaje_detraccion" => $request->porcentaje_detraccion ?? 0,
                "monto_detraccion"     => $request->monto_detraccion ?? 0,
                "porcentaje_percepcion" => $request->porcentaje_percepcion ?? 0,
                "monto_percepcion"     => $request->monto_percepcion ?? 0,

                // Estado de pago
                "state_payment"   => $request->state_payment,  // 1=pendiente, 2=parcial, 3=pagado
                "debt"            => $request->debt,            // saldo pendiente
                "paid_out"        => $request->paid_out,        // monto ya pagado

                // Anticipo (si aplica)
                "n_comprobante_anticipo" => $request->n_comprobante_anticipo,
                "amount_anticipo"        => $request->amount_anticipo ?? 0,

                "description"     => $request->description,
            ]);

            // ── Guardar detalle de productos ──────────────────────────
            foreach ($detalles_venta as $detalle) {
                $campos_detalle = [
                    "sale_id"              => $venta->id,
                    "product_id"           => $detalle["product"]["id"],
                    "product_categorie_id" => $detalle["product"]["categorie_id"],
                    "unidad_medida"        => $detalle["unidad_medida"],

                    // Cantidades y precios
                    "quantity"             => $detalle["quantity"],
                    "price_base"           => $detalle["price_base"],    // precio sin IGV
                    "price_final"          => $detalle["price_final"],   // precio con IGV

                    // Descuento de la línea
                    "discount"             => $detalle["discount"],

                    // Totales de la línea
                    // subtotal = base neta (con descuento aplicado, sin IGV)
                    "subtotal"             => $detalle["subtotal"],

                    // Campos requeridos por Greenter en cada SaleDetail
                    // mto_valor_venta = precio_base × cantidad (ANTES del descuento)
                    "mto_valor_venta"      => $detalle["mto_valor_venta"],
                    // mto_base_igv = base neta sobre la que se calcula el IGV
                    "mto_base_igv"         => $detalle["mto_base_igv"],
                    // porcentaje_igv: 18 (gravado), 4 (IVAP), 0 (exonerado/inafecto)
                    "porcentaje_igv"       => $detalle["porcentaje_igv"],

                    // Impuestos de la línea
                    "igv"                  => $detalle["igv"],
                    // tip_afe_igv: '10'=gravado, '17'=IVAP, '20'=exonerado, '30'=inafecto, '40'=exportación
                    // IMPORTANTE: guardar siempre como string para consistencia con Greenter
                    "tip_afe_igv"          => (string) $detalle["tip_afe_igv"],

                    // ISC (Impuesto Selectivo al Consumo)
                    "percentage_isc"       => $detalle["percentage_isc"] ?? 0,
                    "isc"                  => $detalle["isc"] ?? 0,
                    // Régimen ISC: '01'=Al valor, '02'=Específico, '03'=Al valor/PVP
                    "tipo_isc"             => $detalle["tipo_isc"] ?? '01',
                    // Monto fijo por unidad (solo régimen '02')
                    "monto_isc_fijo"       => $detalle["monto_isc_fijo"] ?? 0,

                    // ICBPER (bolsa plástica)
                    "per_icbper"           => $detalle["per_icbper"] ?? 0,   // monto por unidad (0.50)
                    "icbper"               => $detalle["icbper"] ?? 0,       // total = qty × 0.50

                    // Total de impuestos de la línea = IGV + ISC + ICBPER
                    "total_impuestos"      => $detalle["total_impuestos"] ?? 0,
                ];

                $campos_detalle = $this->sanitizeIscForDetail($campos_detalle, $detalle["product"]["id"]);

                SaleDetail::create($campos_detalle);
            }

            // ── Guardar pagos ─────────────────────────────────────────
            foreach ($pagos_venta as $pago) {
                SalePayment::create([
                    "sale_id"        => $venta->id,
                    "method_payment" => $pago["method_payment"],
                    "amount"         => $pago["amount"],
                    "date_payment"   => $pago["date_payment"],
                ]);
            }

            // ── Descontar stock de cada producto ─────────────────────
            foreach ($detalles_venta as $detalle) {
                $producto = Product::find($detalle["product"]["id"]);
                // decrement es atómico — evita condiciones de carrera si hay concurrencia
                $producto->decrement('stock', $detalle["quantity"]);
            }

            DB::commit();
        } catch (\Throwable $error) {
            DB::rollBack();
            throw new HttpException(500, $error->getMessage());
        }

        return response()->json([
            "code"    => 200,
            "message" => "Venta creada exitosamente",
            "sale_id" => $venta->id,
        ]);
    }

    // ── Ver detalle de una venta ─────────────────────────────────────
    public function show(string $id)
    {
        $venta = Sale::with("notes")->find($id);

        return response()->json([
            "sale" => SaleResource::make($venta),
            "code" => 200,
           
        ]);
    }


  
public function update(Request $request, string $id)
{
    $venta = Sale::findOrFail($id);

    // ── Protección: no editar ventas ya emitidas a SUNAT ─────────
    if ($venta->xml || $venta->cdr) {
        return response()->json([
            "code"    => 405,
            "message" => "No se puede editar una venta ya facturada electrónicamente. " .
                         "Si hay un error, emite una Nota de Crédito.",
        ]);
    }

    $this->validarRegimenEspecial($request);

    $detalles_nuevos = $request->sale_details ?? [];
    $pagos_nuevos    = $request->payments ?? [];

    try {
        DB::beginTransaction();

        // ── 1. Actualizar cabecera de la venta ────────────────────
        $venta->update([
            // Identificación
            "serie"          => $request->serie,
            "n_transaction"  => $request->n_transaction,

            // Cliente
            "client_id"            => $request->client_id,
            "type_client"          => $request->type_client,
            "cod_tipo_doc_cliente" => $request->cod_tipo_doc_cliente,

            // Configuración de la operación
            "currency"       => $request->currency,
            "is_exportacion" => $request->is_exportacion,
            "destino"        => $request->destino,
            "state_sale"     => $request->state_sale,
            "type_payment"   => $request->type_payment,

            // Totales
            "subtotal"        => $request->subtotal,
            "igv"             => $request->igv,
            "total"           => $request->total,
            "discount"        => $request->discount,
            "discount_global" => $request->discount_global,

            // Totales por tipo de operación (para Greenter)
            "mto_oper_gravadas"    => $request->mto_oper_gravadas ?? 0,
            "mto_oper_exoneradas"  => $request->mto_oper_exoneradas ?? 0,
            "mto_oper_inafectas"   => $request->mto_oper_inafectas ?? 0,
            "mto_oper_exportacion" => $request->mto_oper_exportacion ?? 0,
            "mto_oper_gratuitas"   => $request->mto_oper_gratuitas ?? 0,

            // Impuestos desagregados
            "isc_total"       => $request->isc_total ?? 0,
            "icbper_total"    => $request->icbper_total ?? 0,
            "ivap_total"      => $request->ivap_total ?? 0,
            "total_impuestos" => $request->total_impuestos ?? 0,
            "valor_venta"     => $request->valor_venta ?? 0,
            "mto_imp_venta"   => $request->mto_imp_venta ?? 0,
            "redondeo"        => $request->redondeo ?? 0,

            // Regímenes especiales
            "retencion_igv"          => $request->retencion_igv,
            "monto_retencion"        => $request->monto_retencion ?? 0,
            "codigo_detraccion"      => $request->codigo_detraccion,
            "porcentaje_detraccion"  => $request->porcentaje_detraccion ?? 0,
            "monto_detraccion"       => $request->monto_detraccion ?? 0,
            "porcentaje_percepcion"  => $request->porcentaje_percepcion ?? 0,
            "monto_percepcion"       => $request->monto_percepcion ?? 0,

            // Estado de pago
            "state_payment" => $request->state_payment,
            "debt"          => $request->debt,
            "paid_out"      => $request->paid_out,

            "description"   => $request->description,
        ]);

        // ── 2. Sincronizar detalles ───────────────────────────────
        // Estrategia: comparar los detalles que vienen del frontend
        // con los que ya están en la BD.
        //
        // Casos posibles:
        //   a) Ítem con id → ya existe en BD → actualizar
        //   b) Ítem sin id → es nuevo → crear
        //   c) Ítem en BD que no vino → fue eliminado → borrar y devolver stock

        // IDs que vienen del frontend (los que deben quedar)
        $ids_nuevos = collect($detalles_nuevos)
            ->filter(fn($d) => !empty($d['id']))
            ->pluck('id')
            ->toArray();

        // Obtener detalles actuales en BD
        $detalles_actuales = SaleDetail::where('sale_id', $venta->id)->get();

        // Eliminar los que ya no están y devolver su stock
        foreach ($detalles_actuales as $detalle_actual) {
            if (!in_array($detalle_actual->id, $ids_nuevos)) {
                // Devolver stock al producto
                $producto = Product::find($detalle_actual->product_id);
                if ($producto) {
                    $producto->increment('stock', $detalle_actual->quantity);
                }
                $detalle_actual->delete();
            }
        }

        // Crear o actualizar cada detalle que viene del frontend
        foreach ($detalles_nuevos as $detalle) {
            $campos_detalle = [
                "sale_id"              => $venta->id,
                "product_id"           => $detalle["product"]["id"],
                "product_categorie_id" => $detalle["product"]["categorie_id"],
                "unidad_medida"        => $detalle["unidad_medida"],
                "quantity"             => $detalle["quantity"],
                "price_base"           => $detalle["price_base"],
                "price_final"          => $detalle["price_final"],
                "discount"             => $detalle["discount"],
                "subtotal"             => $detalle["subtotal"],

                // Campos requeridos por Greenter
                "mto_valor_venta"      => $detalle["mto_valor_venta"],
                "mto_base_igv"         => $detalle["mto_base_igv"],
                "porcentaje_igv"       => $detalle["porcentaje_igv"],
                "total_impuestos"      => $detalle["total_impuestos"],

                // IGV
                "igv"                  => $detalle["igv"],
                // tip_afe_igv siempre como string
                "tip_afe_igv"          => (string) $detalle["tip_afe_igv"],

                // ISC
                "percentage_isc"       => $detalle["percentage_isc"] ?? 0,
                "isc"                  => $detalle["isc"] ?? 0,
                "tipo_isc"             => $detalle["tipo_isc"] ?? '01',
                "monto_isc_fijo"       => $detalle["monto_isc_fijo"] ?? 0,

                // ICBPER
                "per_icbper"           => $detalle["per_icbper"] ?? 0,
                "icbper"               => $detalle["icbper"] ?? 0,
            ];

            $campos_detalle = $this->sanitizeIscForDetail($campos_detalle, $detalle["product"]["id"]);

            if (!empty($detalle['id'])) {
                // Ítem existente → actualizar
                $detalle_bd = SaleDetail::find($detalle['id']);
                if ($detalle_bd) {
                    // Si cambió la cantidad, ajustar el stock
                    $diferencia_qty = $detalle["quantity"] - $detalle_bd->quantity;
                    if ($diferencia_qty !== 0) {
                        $producto = Product::find($detalle["product"]["id"]);
                        if ($producto) {
                            if ($diferencia_qty > 0) {
                                // Pide más → descontar stock
                                $producto->decrement('stock', $diferencia_qty);
                            } else {
                                // Pide menos → devolver stock
                                $producto->increment('stock', abs($diferencia_qty));
                            }
                        }
                    }
                    $detalle_bd->update($campos_detalle);
                }
            } else {
                // Ítem nuevo → crear y descontar stock
                SaleDetail::create($campos_detalle);
                $producto = Product::find($detalle["product"]["id"]);
                if ($producto) {
                    $producto->decrement('stock', $detalle["quantity"]);
                }
            }
        }

        DB::commit();

    } catch (\Throwable $error) {
        DB::rollBack();
        throw new HttpException(500, $error->getMessage());
    }

    return response()->json([
        "code"    => 200,
        "message" => "Venta actualizada correctamente",
    ]);
}
    

    // ── Eliminar venta ───────────────────────────────────────────────
    public function destroy(string $id)
    {
        $venta = Sale::find($id);

        // Protección: no se puede eliminar una venta ya emitida
        if ($venta->xml || $venta->cdr) {
            return response()->json([
                "code"    => 405,
                "message" => "No se puede eliminar una venta ya facturada electrónicamente.",
            ]);
        }

        $venta->delete();

        return response()->json([
            "code"    => 200,
            "message" => "Venta eliminada correctamente",
        ]);
    }
}