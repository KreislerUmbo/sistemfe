<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Resources\Product\ProductCollection;
use App\Models\Advance\Advance;
use App\Models\Advance\AdvanceRefund;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\Company;
use App\Models\Product\Product;
use App\Models\Sale\Note;
use App\Models\Sale\NoteDetail;
use App\Models\Sale\NoteMotivo;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Services\NoteDetailAmountCalculator;
use App\Services\SerieNotaResolver;
use App\Services\TotalesComprobanteCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Luecano\NumeroALetras\NumeroALetras;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

// ── Contrato del request de store()/preview() ─────────────────────────────
// {
//   sale_id: number,
//   tipo_doc: '07'|'08',
//   cod_motivo: string(2),
//   des_motivo?: string,          // opcional, default = label del motivo
//   tipo_afectacion: 'total'|'parcial',
//   reponer_stock?: boolean,
//   items?: [                      // requerido solo si tipo_afectacion === 'parcial'
//     {
//       sale_detail_id?: number,   // omitir para concepto libre de ND
//       product_id: number,
//       quantity: number,
//       subtotal?: number,         // requerido solo si sale_detail_id se omite
//     }
//   ]
// }
class NotaElectronicaController extends Controller
{
    // Motivos de catálogo 09 (NC) que ajustan VALOR sin devolución física de
    // unidades — sus note_details conservan quantity = cantidad original de
    // la línea (ver NoteDetailAmountCalculator::porMonto() y la rama NC04 en
    // store()/preview()). Se excluyen del tope de "cantidad disponible para
    // acreditar" (armarLineasParciales(), validarYReservarCorrelativoNota())
    // porque no consumen cantidad — sumarlos ahí bloquearía notas legítimas
    // sobre una línea que ya tuvo un ajuste de valor.
    private const MOTIVOS_VALOR_SIN_CANTIDAD = ['04', '05', '08', '09'];

    protected $greenter_service;
    protected $totales_calculator;
    protected $note_detail_calculator;
    protected $serie_resolver;

    public function __construct(
        GreenterService $greenter_service,
        TotalesComprobanteCalculator $totales_calculator,
        NoteDetailAmountCalculator $note_detail_calculator,
        SerieNotaResolver $serie_resolver
    ) {
        $this->greenter_service        = $greenter_service;
        $this->totales_calculator      = $totales_calculator;
        $this->note_detail_calculator  = $note_detail_calculator;
        $this->serie_resolver          = $serie_resolver;
    }

    // ── Configuración para el formulario de emisión de notas ──────────
    // Catálogo de motivos (09 NC / 10 ND, con permite_total/permite_parcial/
    // solo_factura — ver note_motivos) y los productos "especiales de nota"
    // (is_especial_nota=1) que sirven para conceptos libres de ND que no
    // están ligados a ningún ítem realmente vendido (ej. intereses por mora).
    public function config()
    {
        $motivos = NoteMotivo::orderBy('catalogo')->orderBy('codigo')->get();

        $productos_especiales_nota = Product::where('state', 1)
            ->where('is_especial_nota', 1)
            ->orderBy('title', 'asc')
            ->get();

        return response()->json([
            'motivos'       => $motivos,
            'product_notes' => ProductCollection::make($productos_especiales_nota),
        ]);
    }

    // ── Buscar venta aceptada por SUNAT (flujo "Nueva Nota Crédito/Débito"
    // desde /nota/list, sin partir de una venta ya elegida) ────────────
    // Solo ventas con xml/cdr (aceptadas) — no tiene sentido emitir una
    // nota sobre un comprobante que SUNAT nunca aceptó. Busca por
    // cliente (nombre/documento) o por serie-correlativo/n_operacion.
    public function buscarVenta(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['sales' => []]);
        }

        $ventas = Sale::with('client:id,full_name,n_document')
            ->whereNotNull('xml')
            ->whereNotNull('cdr')
            ->where(function ($query) use ($q) {
                $query->whereRaw(
                    "(COALESCE(serie, '') || '-' || LPAD(COALESCE(correlativo::text, ''), 8, '0')) ILIKE ?",
                    ["%{$q}%"]
                )
                    ->orWhere('n_operacion', 'ILIKE', "%{$q}%")
                    ->orWhereHas('client', function ($cq) use ($q) {
                        $cq->whereRaw(
                            "(COALESCE(full_name, '') || ' ' || COALESCE(n_document, '')) ILIKE ?",
                            ["%{$q}%"]
                        );
                    });
            })
            ->orderBy('id', 'desc')
            ->limit(15)
            ->get(['id', 'serie', 'correlativo', 'n_operacion', 'client_id', 'currency', 'total']);

        return response()->json(['sales' => $ventas]);
    }

    // ── Consultar una nota (para refrescar su estado tras enviar) ──────
    public function show(string $id)
    {
        $nota = Note::with('note_details.product')->findOrFail($id);

        return response()->json($nota);
    }

    // ── Listado global de notas (pendientes + emitidas) ─────────────────
    // A diferencia de la tabla embebida en Sale.notes (compacta, por
    // venta), este listado es la vista de auditoría/seguimiento: permite
    // encontrar notas 'pendiente' que quedaron sin enviar a SUNAT después
    // de que el usuario salió del formulario de creación, sin tener que
    // recordar a qué venta pertenecían.
    public function index(Request $request)
    {
        $query = Note::with(['sale:id,serie,correlativo', 'client:id,full_name,n_document'])
            ->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('tipo_doc')) {
            $query->where('tipo_doc', $request->tipo_doc);
        }

        $notas = $query->paginate(25);

        return response()->json([
            'total'    => $notas->total(),
            'paginate' => 25,
            'notes'    => $notas->items(),
        ]);
    }

    // ── Crear la nota (sin enviar a SUNAT todavía) ─────────────────────
    // Dos pasos separados (crear → enviar), igual que las ventas: así un
    // error de formulario no quema un correlativo. El correlativo real se
    // asigna recién en enviarNotaSunat().
    public function store(Request $request)
    {
        $sale = Sale::with('sale_details.product')->findOrFail($request->sale_id);

        // ── La venta debe estar aceptada por SUNAT ───────────────────
        if (!($sale->xml && $sale->cdr)) {
            abort(422, 'Solo se pueden emitir notas sobre comprobantes ya aceptados por SUNAT.');
        }

        // ── Bloqueo si ya existe una NC total aceptada ────────────────
        // Chequeo "en caliente" para feedback inmediato — el autoritativo
        // con lock corre en validarYReservarCorrelativoNota() al enviar.
        if ($sale->tieneNotaCreditoTotalAceptada()) {
            $notaTotal = $sale->notes()
                ->where('tipo_doc', '07')
                ->where('tipo_afectacion', 'total')
                ->where('status', 'aceptado')
                ->first();
            abort(422, "Esta venta ya cuenta con una Nota de Crédito total aceptada ({$notaTotal->n_operacion}); no se pueden emitir notas adicionales sobre este comprobante.");
        }

        $tipo_doc = (string) $request->tipo_doc;
        if (!in_array($tipo_doc, ['07', '08'])) {
            abort(422, 'tipo_doc inválido: debe ser 07 (Nota de Crédito) o 08 (Nota de Débito).');
        }

        $tipo_doc_afectado = str_starts_with($sale->serie, 'F') ? '01' : '03';

        [$motivo, $des_motivo] = $this->validarMotivo($request, $tipo_doc, $tipo_doc_afectado);

        $tipo_afectacion = $request->tipo_afectacion;
        if (!in_array($tipo_afectacion, ['total', 'parcial'])) {
            abort(422, 'tipo_afectacion inválido: debe ser total o parcial.');
        }
        if ($tipo_afectacion === 'total' && !$motivo->permite_total) {
            abort(422, "El motivo \"{$motivo->label}\" no permite nota total.");
        }
        if ($tipo_afectacion === 'parcial' && !$motivo->permite_parcial) {
            abort(422, "El motivo \"{$motivo->label}\" no permite nota parcial.");
        }

        // ── Armar las líneas de la nota ────────────────────────────────
        // NC04 (descuento global) es un caso aparte: clona TODAS las
        // líneas (como una nota total) pero aplica un único descuento
        // sobre el conjunto — no encaja en el dicotomía total/parcial de
        // armarLineasParciales() (no hay selección de ítems).
        $esDescuentoGlobal = $this->esMotivoDescuentoGlobal($motivo);

        if ($esDescuentoGlobal) {
            $lineasNota = $this->clonarLineasTotal($sale);
            $descuentoGlobalNota = $this->validarDescuentoGlobal($request, $sale);
        } else {
            $lineasNota = $tipo_afectacion === 'total'
                ? $this->clonarLineasTotal($sale)
                : $this->armarLineasParciales($sale, $tipo_doc, $request->items ?? []);
            $descuentoGlobalNota = 0;
        }

        if ($lineasNota->isEmpty()) {
            abort(422, 'La nota debe tener al menos una línea.');
        }

        // ── Calcular totales (compartido con ventas) ────────────────────
        $datos = $this->totales_calculator->calcular($lineasNota, $descuentoGlobalNota);

        $nota = DB::transaction(function () use (
            $sale, $tipo_doc, $tipo_doc_afectado, $motivo, $des_motivo,
            $tipo_afectacion, $datos, $lineasNota, $request, $descuentoGlobalNota
        ) {
            $nota = Note::create([
                'sale_id'              => $sale->id,
                'tipo_doc'             => $tipo_doc,
                'tipo_doc_afectado'    => $tipo_doc_afectado,
                'serie_afectada'       => $sale->serie,
                'correlativo_afectado' => $sale->correlativo,
                'serie'                => $this->serie_resolver->resolver($tipo_doc, $tipo_doc_afectado),
                'cod_motivo'           => $motivo->codigo,
                'des_motivo'           => $des_motivo,
                'tipo_afectacion'      => $tipo_afectacion,
                'client_id'            => $sale->client_id,
                'cod_tipo_doc_cliente' => $sale->cod_tipo_doc_cliente,
                'currency'             => $sale->currency,
                'discount_global'      => $descuentoGlobalNota,
                'mto_oper_gravadas'    => $datos['mto_oper_gravadas'],
                'mto_oper_exoneradas'  => $datos['mto_oper_exoneradas'],
                'mto_oper_inafectas'   => $datos['mto_oper_inafectas'],
                'mto_oper_exportacion' => $datos['mto_oper_exportacion'],
                'mto_oper_gratuitas'   => $datos['mto_oper_gratuitas'],
                'mto_igv'              => $datos['mto_igv'],
                'icbper_total'         => $datos['icbper'],
                'isc_total'            => $datos['isc'],
                'ivap_total'           => $datos['mto_ivap'],
                'total_impuestos'      => $datos['total_impuestos'],
                'valor_venta'          => $datos['valor_venta'],
                'mto_imp_venta'        => $datos['mto_imp_venta'],
                'redondeo'             => $datos['redondeo'],
                'reponer_stock'        => $request->boolean('reponer_stock'),
                'status'               => 'pendiente',
                'user_id'              => auth('api')->id(),
            ]);

            foreach ($lineasNota as $linea) {
                NoteDetail::create(array_merge($linea, ['note_id' => $nota->id]));
            }

            return $nota;
        });

        return response()->json([
            'note' => $nota->load('note_details.product'),
        ]);
    }

    // ── Vista previa de totales sin persistir ─────────────────────────
    // Corre el mismo cálculo/validación que store() para que el frontend
    // muestre montos en vivo sin reimplementar el redondeo SUNAT en TS.
    public function preview(Request $request)
    {
        $sale = Sale::with('sale_details.product')->findOrFail($request->sale_id);
        $tipo_doc = (string) $request->tipo_doc;

        // preview() no valida el motivo completo (ver validarMotivo(), solo
        // lo llama store()) — para NC04 alcanza con mirar cod_motivo directo.
        $esDescuentoGlobal = $tipo_doc === '07' && (string) $request->cod_motivo === '04';

        if ($esDescuentoGlobal) {
            $lineasNota = $this->clonarLineasTotal($sale);
            $descuentoGlobalNota = $this->validarDescuentoGlobal($request, $sale);
        } else {
            $lineasNota = $request->tipo_afectacion === 'total'
                ? $this->clonarLineasTotal($sale)
                : $this->armarLineasParciales($sale, $tipo_doc, $request->items ?? []);
            $descuentoGlobalNota = 0;
        }

        $datos = $this->totales_calculator->calcular($lineasNota, $descuentoGlobalNota);

        return response()->json([
            'totales' => $datos,
            'lineas'  => $lineasNota->values(),
        ]);
    }

    // ── Enviar la nota a SUNAT ──────────────────────────────────────────
    public function enviarNotaSunat(Request $request)
    {
        $nota = Note::with(['note_details.product', 'client'])->findOrFail($request->note_id);

        // Una nota ya resuelta (aceptada o rechazada) no se reintenta sobre
        // la misma fila — hay que crear una nota nueva. Evita pisar un
        // correlativo ya quemado sin dejar rastro.
        if (in_array($nota->status, ['aceptado', 'rechazado'])) {
            abort(405, "Esta nota ya fue procesada (estado: {$nota->status}). Para corregir, cree una nota nueva.");
        }

        // ── Claim atómico — evita que un doble clic dispare dos intentos ──
        // de envío sobre la misma fila (ver plan, "Idempotencia de envío").
        $reclamado = Note::where('id', $nota->id)->where('status', 'pendiente')->update(['status' => 'enviando']);
        if ($reclamado !== 1) {
            abort(409, 'Esta nota ya está siendo enviada.');
        }

        $empresa = Company::first();

        try {
            $correlativo = $this->validarYReservarCorrelativoNota($nota);
        } catch (Throwable $e) {
            $nota->update([
                'status'                => 'rechazado',
                'sunat_error_message'   => $e->getMessage(),
                'sunat_sent_at'         => now(),
                'sunat_sent_by_user_id' => auth('api')->id(),
            ]);

            return response()->json([
                'response' => ['error' => ['message' => $e->getMessage()]],
            ]);
        }

        $nota->refresh();
        $nota->load('note_details.product');

        $datos_nota = $this->totales_calculator->calcular($nota->note_details, (float) ($nota->discount_global ?? 0));
        $datos_nota['tipo_doc']    = $nota->tipo_doc;
        $datos_nota['serie']       = $nota->serie;
        $datos_nota['correlativo'] = $correlativo;
        $datos_nota['tipo_moneda'] = $nota->currency ?? 'PEN';
        $datos_nota = $this->armarLeyendasNota($datos_nota, $nota->currency);

        $see          = $this->greenter_service->getSee();
        $noteGreenter = $this->greenter_service->getNote($datos_nota, $empresa, $nota);
        $result       = $see->send($noteGreenter);

        $respuesta = $this->greenter_service->procesarRespuestaSunat($result);

        // ── Si SUNAT aceptó la nota ─────────────────────────────────────
        if (!isset($respuesta['error'])) {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput       = true;
            $dom->loadXML($see->getFactory()->getLastXml());
            $xml_formateado = $dom->saveXML();

            $nombre_xml = "R-" . env("RUC") . "-"
                . $correlativo . "-"
                . $nota->serie . "-"
                . $nota->id . "_"
                . now()->format('Ymd_His') . ".xml";

            $xml = $see->getFactory()->getLastXml();
            Storage::disk('public')->put('xml/' . $nombre_xml, $xml_formateado);
            $ruta_publica_xml = Storage::url('xml/' . $nombre_xml);

            $hash_cpe = $this->greenter_service->extraerHashDigest($xml);

            $nota->update([
                'n_operacion'           => $nota->serie . '-' . str_pad((string) $correlativo, 8, '0', STR_PAD_LEFT),
                'cdr'                   => $respuesta['cdrZip'],
                'xml'                   => $ruta_publica_xml,
                'hash_cpe'              => $hash_cpe,
                'status'                => 'aceptado',
                'sunat_sent_at'         => now(),
                'sunat_sent_by_user_id' => auth('api')->id(),
            ]);

            // ── Reposición de stock — solo tras aceptación real de SUNAT ──
            // (no al crear la nota: si SUNAT rechaza, no debe haberse
            // repuesto stock por un documento que nunca llegó a existir)
            if ($nota->reponer_stock) {
                foreach ($nota->note_details as $detalle) {
                    if ($detalle->sale_detail_id) {
                        Product::where('id', $detalle->product_id)->increment('stock', $detalle->quantity);
                    }
                }
            }

            // ── Reserva de Agencia de Viajes: liberar el ReservaVenta
            // original (hallazgo de auditoría 2026-08-24) ── una NC total
            // (07, tipo_afectacion='total') anula por completo la venta
            // original. Si esa venta nació de facturar una reserva
            // (ReservaFacturacionController::store()), su ReservaVenta debe
            // desaparecer acá — si no, pasajerosYaFacturadosIds()/
            // itemsYaFacturadosIds() siguen contando esa venta como
            // vigente para siempre y la reserva queda bloqueada para
            // volver a facturar esos pasajeros/ítems, sin ningún camino de
            // recuperación por la API (no hay ningún ReservaVenta::update()/
            // delete() en el resto del proyecto salvo este y
            // SaleController::destroy()).
            if ($nota->tipo_doc === '07' && $nota->tipo_afectacion === 'total') {
                ReservaVenta::where('sale_id', $nota->sale_id)->delete();
            }

            // ── Si esta nota es el reembolso de un adelanto ────────────
            // (ver módulo Adelantos, AdvanceController::refund()), recién
            // acá se actualiza el saldo — una NC creada pero rechazada no
            // debe dejar el adelanto marcado como reembolsado.
            $reembolsoAdelanto = AdvanceRefund::where('note_id', $nota->id)->first();
            if ($reembolsoAdelanto) {
                $adelanto = Advance::where('id', $reembolsoAdelanto->advance_id)->lockForUpdate()->first();
                $adelanto->refunded_amount = round(
                    (float) $adelanto->refunded_amount + (float) $reembolsoAdelanto->amount_refunded,
                    2
                );
                $adelanto->refreshStatus();
                $adelanto->save();
            }

            return response()->json([
                'note'     => $nota->fresh('note_details.product'),
                'response' => $respuesta,
            ]);
        }

        // ── Si SUNAT rechazó ──────────────────────────────────────────────
        $nota->update([
            'status'                => 'rechazado',
            'sunat_error_code'      => $respuesta['error']['code'] ?? null,
            'sunat_error_message'   => $respuesta['error']['message'] ?? null,
            'sunat_sent_at'         => now(),
            'sunat_sent_by_user_id' => auth('api')->id(),
        ]);

        return response()->json(['response' => $respuesta]);
    }

    // ── Validación autoritativa + reserva de correlativo ────────────────
    // Corre bajo lock, dentro de la transacción de envío — no solo al
    // crear la nota — para cerrar la condición de carrera entre dos
    // requests casi simultáneos (dos pestañas, doble clic en otra nota
    // sobre la misma venta/línea). Ver plan, "Concurrencia".
    private function validarYReservarCorrelativoNota(Note $nota): int
    {
        return DB::transaction(function () use ($nota) {
            $sale = Sale::where('id', $nota->sale_id)->lockForUpdate()->first();

            if ($sale->tieneNotaCreditoTotalAceptada()) {
                throw new HttpException(422, 'Esta venta ya cuenta con una Nota de Crédito total aceptada; no se pueden emitir notas adicionales sobre este comprobante.');
            }

            // Cantidad disponible — solo para líneas de Nota de Crédito.
            // Las líneas de Nota de Débito no tienen tope (son un cargo
            // adicional, no una merma). Los motivos "de valor" (04
            // descuento global, 05/08/09 modo monto) mantienen quantity =
            // cantidad original de la línea (no representan una devolución
            // física — ver NoteDetailAmountCalculator::porMonto()), así que
            // ni cuentan como "ya acreditado" para otras notas ni se
            // validan ellos mismos contra este tope de cantidad.
            if ($nota->tipo_doc === '07' && !in_array($nota->cod_motivo, self::MOTIVOS_VALOR_SIN_CANTIDAD, true)) {
                foreach ($nota->note_details as $detalle) {
                    if (!$detalle->sale_detail_id) {
                        continue;
                    }

                    $original = SaleDetail::where('id', $detalle->sale_detail_id)->lockForUpdate()->first();

                    $yaAcreditado = NoteDetail::where('sale_detail_id', $detalle->sale_detail_id)
                        ->where('id', '!=', $detalle->id)
                        ->whereHas('note', fn ($q) => $q->where('tipo_doc', '07')
                            ->where('status', 'aceptado')
                            ->whereNotIn('cod_motivo', self::MOTIVOS_VALOR_SIN_CANTIDAD))
                        ->sum('quantity');

                    if ($yaAcreditado + $detalle->quantity > $original->quantity) {
                        $disponible = max(0, $original->quantity - $yaAcreditado);
                        throw new HttpException(422, "La línea del producto excede la cantidad disponible para acreditar (disponible: {$disponible}).");
                    }
                }
            }

            $ultima = Note::where('tipo_doc', $nota->tipo_doc)
                ->where('serie', $nota->serie)
                ->whereNotNull('correlativo')
                ->orderBy('correlativo', 'desc')
                ->lockForUpdate()
                ->first();

            $nuevo = $ultima ? $ultima->correlativo + 1 : 1;
            $nota->update(['correlativo' => $nuevo]);

            return $nuevo;
        });
    }

    // ── Validar el motivo contra el catálogo 09/10 ──────────────────────
    private function validarMotivo(Request $request, string $tipo_doc, string $tipo_doc_afectado): array
    {
        $catalogo = $tipo_doc === '07' ? '09' : '10';

        $motivo = NoteMotivo::where('catalogo', $catalogo)
            ->where('codigo', $request->cod_motivo)
            ->first();

        if (!$motivo) {
            abort(422, "Motivo inválido para el catálogo {$catalogo}.");
        }

        if ($motivo->solo_factura && $tipo_doc_afectado === '03') {
            abort(422, "El motivo \"{$motivo->label}\" no aplica sobre boletas (consumidor final).");
        }

        $des_motivo = trim((string) ($request->des_motivo ?? ''));

        // Motivo "Otros conceptos" (09-10 y 10-03) requiere descripción
        // detallada — el label genérico no es suficiente para SUNAT.
        $requiereDescripcionDetallada = ($catalogo === '09' && $motivo->codigo === '10')
            || ($catalogo === '10' && $motivo->codigo === '03');

        if ($requiereDescripcionDetallada && $des_motivo === '') {
            abort(422, "El motivo \"{$motivo->label}\" requiere una descripción detallada.");
        }

        if ($des_motivo === '') {
            $des_motivo = $motivo->label;
        }

        return [$motivo, $des_motivo];
    }

    // ── NC04 (Descuento global) — sin tabla de ítems, un solo monto ─────
    private function esMotivoDescuentoGlobal(NoteMotivo $motivo): bool
    {
        return $motivo->catalogo === '09' && $motivo->codigo === '04';
    }

    // ── Validar el monto de descuento global (NC04) ──────────────────────
    // No puede superar el valor de venta (sin impuestos) de la venta
    // original — mismo criterio de tope que TotalesComprobanteCalculator
    // usa para prorratear (solo gravadas/exoneradas/inafectas reciben
    // descuento, exportación e IVAP quedan fuera).
    private function validarDescuentoGlobal(Request $request, Sale $sale): float
    {
        $descuento = round((float) ($request->descuento_global ?? 0), 2);
        if ($descuento <= 0) {
            abort(422, 'El descuento global debe ser mayor a cero.');
        }

        $valorVentaTotal = (float) $sale->sale_details
            ->whereIn('tip_afe_igv', ['10', '20', '30'])
            ->sum('subtotal');

        if ($descuento > $valorVentaTotal) {
            abort(422, "El descuento global no puede superar el valor de venta de la venta (S/ {$valorVentaTotal}).");
        }

        return $descuento;
    }

    // ── Clonar todas las líneas de la venta tal cual (nota TOTAL) ───────
    // Copia directa de los montos ya almacenados — cero recálculo, cero
    // riesgo de descuadre de céntimos contra lo que SUNAT ya aceptó.
    private function clonarLineasTotal(Sale $sale): Collection
    {
        return $sale->sale_details->map(function ($d) {
            return [
                'sale_detail_id'  => $d->id,
                'product_id'      => $d->product_id,
                'tip_afe_igv'     => (string) $d->tip_afe_igv,
                'porcentaje_igv'  => $d->porcentaje_igv,
                'tipo_isc'        => $d->tipo_isc,
                'percentage_isc'  => $d->percentage_isc,
                'monto_isc_fijo'  => $d->monto_isc_fijo,
                'per_icbper'      => $d->per_icbper,
                'quantity'        => $d->quantity,
                'price_base'      => $d->price_base,
                'price_final'     => $d->price_final,
                'discount'        => $d->discount,
                'subtotal'        => $d->subtotal,
                'igv'             => $d->igv,
                'mto_valor_venta' => $d->mto_valor_venta,
                'mto_base_igv'    => $d->mto_base_igv,
                'total_impuestos' => $d->total_impuestos,
                'icbper'          => $d->icbper,
                'isc'             => $d->isc,
                'unidad_medida'   => $d->unidad_medida,
                'description'     => $d->description,
            ];
        });
    }

    // ── Armar líneas seleccionadas por el usuario (nota PARCIAL) ────────
    // Cada ítem referencia una línea ya vendida (sale_detail_id → se
    // prorratea por cantidad) o, solo para ND, un concepto libre sobre un
    // producto is_especial_nota=1 (sin sale_detail_id, no afecta stock).
    private function armarLineasParciales(Sale $sale, string $tipoDoc, array $items): Collection
    {
        $lineas = collect();

        foreach ($items as $item) {
            $saleDetailId = $item['sale_detail_id'] ?? null;

            if ($saleDetailId) {
                $original = $sale->sale_details->firstWhere('id', $saleDetailId);
                if (!$original) {
                    abort(422, "La línea sale_detail #{$saleDetailId} no pertenece a esta venta.");
                }

                $tieneMonto = array_key_exists('monto', $item) && $item['monto'] !== null && $item['monto'] !== '';

                // ── Modo monto — ajuste de valor sin devolución de unidades
                // (NC 05/08/09, ND 02 — ver modo_parcial en note_motivos) ──
                if ($tieneMonto) {
                    $monto = round((float) $item['monto'], 2);
                    if ($monto <= 0) {
                        abort(422, "El monto de la línea del producto \"{$original->product->title}\" debe ser mayor a cero.");
                    }

                    // Tope: el monto no puede superar el valor con IGV de la
                    // línea original menos lo ya acreditado por NC aceptadas
                    // sobre esa misma línea (mismo criterio que el tope de
                    // cantidad de abajo, pero en soles).
                    $valorLineaConIgv = round((float) $original->subtotal + (float) $original->igv, 2);

                    if ($tipoDoc === '07') {
                        $yaAcreditadoMonto = (float) NoteDetail::where('sale_detail_id', $saleDetailId)
                            ->whereHas('note', fn ($q) => $q->where('tipo_doc', '07')->where('status', 'aceptado'))
                            ->selectRaw('COALESCE(SUM(subtotal + igv), 0) as total')
                            ->value('total');
                        $disponibleMonto = round($valorLineaConIgv - $yaAcreditadoMonto, 2);
                        if ($monto > $disponibleMonto) {
                            abort(422, "El monto de la línea del producto \"{$original->product->title}\" excede el disponible para acreditar (disponible: S/ {$disponibleMonto}).");
                        }
                    } elseif ($monto > $valorLineaConIgv) {
                        abort(422, "El monto de la línea del producto \"{$original->product->title}\" no puede superar el valor original de la línea (S/ {$valorLineaConIgv}).");
                    }

                    $montos = $this->note_detail_calculator->porMonto($original, $monto);
                    $lineas->push(array_merge($montos, [
                        'sale_detail_id' => $original->id,
                        'product_id'     => $original->product_id,
                    ]));
                    continue;
                }

                // ── Modo cantidad (NC 07 — devolución por ítem) ──────────
                $cantidad = (float) ($item['quantity'] ?? 0);
                if ($cantidad <= 0) {
                    abort(422, 'La cantidad de cada línea debe ser mayor a cero.');
                }

                // Chequeo blando de cantidad disponible — solo NC. El
                // autoritativo con lock corre en enviarNotaSunat(). Excluye
                // motivos "de valor" (ver MOTIVOS_VALOR_SIN_CANTIDAD): no
                // consumen cantidad, así que no deben contar como "ya
                // acreditado" acá.
                if ($tipoDoc === '07') {
                    $yaAcreditado = NoteDetail::where('sale_detail_id', $saleDetailId)
                        ->whereHas('note', fn ($q) => $q->where('tipo_doc', '07')
                            ->where('status', 'aceptado')
                            ->whereNotIn('cod_motivo', self::MOTIVOS_VALOR_SIN_CANTIDAD))
                        ->sum('quantity');
                    $disponible = $original->quantity - $yaAcreditado;
                    if ($cantidad > $disponible) {
                        abort(422, "La línea del producto \"{$original->product->title}\" excede la cantidad disponible para acreditar (disponible: {$disponible}).");
                    }
                }

                $montos = $this->note_detail_calculator->prorratear($original, $cantidad);
                $lineas->push(array_merge($montos, [
                    'sale_detail_id' => $original->id,
                    'product_id'     => $original->product_id,
                ]));
                continue;
            }

            // ── Concepto libre — solo válido para ND ─────────────────────
            if ($tipoDoc !== '08') {
                abort(422, 'Solo las Notas de Débito admiten conceptos libres sin línea de venta asociada.');
            }

            $cantidad = (float) ($item['quantity'] ?? 0);
            if ($cantidad <= 0) {
                abort(422, 'La cantidad de cada línea debe ser mayor a cero.');
            }

            $producto = Product::find($item['product_id'] ?? null);
            if (!$producto || !$producto->is_especial_nota) {
                abort(422, 'El concepto libre debe usar un producto marcado como especial de nota.');
            }

            $subtotal = round((float) ($item['subtotal'] ?? 0), 2);
            if ($subtotal <= 0) {
                abort(422, "El concepto \"{$producto->title}\" requiere un monto (subtotal) mayor a cero.");
            }

            $tipAfeIgv = (string) ($producto->tip_afe_igv_default ?? '10');
            $porcentajeIgv = match (true) {
                in_array($tipAfeIgv, ['10', '11']) => 18,
                $tipAfeIgv === '17' => 4,
                default => 0,
            };
            $igv = $porcentajeIgv > 0 ? round($subtotal * $porcentajeIgv / 100, 2) : 0;

            $lineas->push([
                'sale_detail_id'  => null,
                'product_id'      => $producto->id,
                'tip_afe_igv'     => $tipAfeIgv,
                'porcentaje_igv'  => $porcentajeIgv,
                'tipo_isc'        => null,
                'percentage_isc'  => 0,
                'monto_isc_fijo'  => 0,
                'per_icbper'      => 0,
                'quantity'        => $cantidad,
                'price_base'      => round($subtotal / $cantidad, 6),
                'price_final'     => round(($subtotal + $igv) / $cantidad, 6),
                'discount'        => 0,
                'subtotal'        => $subtotal,
                'igv'             => $igv,
                'mto_valor_venta' => $subtotal,
                'mto_base_igv'    => $subtotal,
                'total_impuestos' => $igv,
                'icbper'          => 0,
                'isc'             => 0,
                'unidad_medida'   => $producto->unidad_medida,
                'description'     => $producto->title,
            ]);
        }

        return $lineas;
    }

    // ── Armar la leyenda del monto en letras — currency-aware ───────────
    // A diferencia de FacturacionElectronicaController::armarLeyendas(),
    // que hardcodea "SOLES", esta versión respeta la moneda de la nota
    // (heredada de la venta), igual que Sale::resumenImpresion().
    private function armarLeyendasNota(array $datos, ?string $currency): array
    {
        $convertidor = new NumeroALetras();

        $datos['legends'] = [
            [
                'code'  => '1000',
                'value' => $convertidor->toInvoice(
                    $datos['mto_imp_venta'],
                    2,
                    $currency === 'USD' ? 'DOLARES' : 'SOLES'
                ),
            ],
        ];

        return $datos;
    }
}
