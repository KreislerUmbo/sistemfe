<?php

namespace App\Http\Controllers\Advance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sale\NotaElectronicaController;
use App\Models\Advance\Advance;
use App\Models\Advance\AdvanceRefund;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Models\Product\Product;
use App\Models\Sale\Note;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Models\Sale\SalePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\SerieComprobanteService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdvanceController extends Controller
{
    // Producto "especial" usado para la línea de sale_details del adelanto —
    // ver database/migrations/2026_07_11_100004_seed_advance_special_product.php.
    // sale_details.product_id/product_categorie_id son NOT NULL, así que no
    // hay forma de registrar esta línea sin un producto real (mismo patrón
    // que NotaElectronicaController::armarLineasParciales() usa para
    // conceptos libres de Nota de Débito).
    private const SKU_PRODUCTO_ADELANTO = 'ADELANTO-001';

    public function __construct(private SerieComprobanteService $serieComprobanteService)
    {
    }

    // ── Módulo de series de comprobantes: resolver tipo/serie del adelanto ──
    // Un adelanto SIEMPRE es un documento fiscal (factura si RUC, boleta si
    // no) — el IGV nace al recibirse el pago (obligación SUNAT), nunca un
    // documento interno. Corre ANTES de abrir la transacción, mismo motivo
    // que el resto de guards de este método: el catch(\Throwable) genérico
    // de más abajo convertiría un 422 lanzado dentro de la transacción en
    // un 500.
    private function resolverSerieComprobanteAdelanto(Client $cliente, string $moneda): array
    {
        // '6' = RUC (Catálogo 06 SUNAT) → Factura. Cualquier otro → Boleta.
        // Misma regla que ya existía, ahora resuelta contra el módulo de
        // series en vez de un string hardcodeado.
        $codigoTipo = $cliente->cod_tipo_doc_sunat === '6' ? '01' : '03';

        $resuelta = $this->serieComprobanteService->resolverParaUsuario(
            auth('api')->user(),
            $codigoTipo,
            $moneda
        );

        // Defensivo: estructuralmente $codigoTipo solo puede ser '01'/'03'
        // arriba, así que esto no debería poder dispararse hoy — pero si
        // algún cambio futuro en esta derivación llegara a colar 'NV' (u
        // otro tipo interno) por error, un adelanto NUNCA debe emitirse
        // como documento no-fiscal: el IGV ya nació al recibirse el pago.
        if (!$resuelta['tipo']->es_documento_sunat) {
            throw new HttpException(
                422,
                "Un adelanto debe emitirse siempre con un comprobante fiscal (factura/boleta) " .
                "— el tipo resuelto ('{$resuelta['tipo']->codigo}') no es válido para adelantos."
            );
        }

        return $resuelta;
    }

    // ── Listado de adelantos ──────────────────────────────────────────
    // Tier 3 (2026-08-24): antes sin ningún filtro — con más de ~25
    // adelantos activos era imposible encontrar uno puntual.
    public function index(Request $request)
    {
        $query = Advance::with(['client', 'sale']);

        if ($request->filled('search')) {
            $busqueda = $request->search;
            $query->whereHas('client', function ($q) use ($busqueda) {
                $q->where('full_name', 'ilike', "%{$busqueda}%")
                    ->orWhere('n_document', 'ilike', "%{$busqueda}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }

        $adelantos = $query->orderBy('id', 'desc')->paginate(25);

        return response()->json([
            "total"     => $adelantos->total(),
            "paginate"  => 25,
            "advances"  => $adelantos->items(),
        ]);
    }

    // ── Detalle de un adelanto ─────────────────────────────────────────
    public function show(string $id)
    {
        $adelanto = Advance::with(['client', 'sale.sale_payments', 'applications.sale', 'refunds.note', 'correctedFromSale'])
            ->findOrFail($id);

        return response()->json(["advance" => $adelanto]);
    }

    // ── Saldo disponible de adelantos de un cliente ──────────────────
    // GET /clients/{id}/advances?available=true — usado para poblar el
    // selector de adelantos aplicables en el checkout de venta.
    public function availableForClient(Request $request, string $clientId)
    {
        $query = Advance::where('client_id', $clientId);

        if ($request->boolean('available', true)) {
            $query->whereRaw('amount - applied_amount - refunded_amount > 0');
        }

        $adelantos = $query->orderBy('id', 'desc')->get();

        return response()->json([
            "advances" => $adelantos->map(fn (Advance $a) => [
                "id"                => $a->id,
                "sale_id"           => $a->sale_id,
                "amount"            => (float) $a->amount,
                "applied_amount"    => (float) $a->applied_amount,
                "refunded_amount"   => (float) $a->refunded_amount,
                "available_balance" => $a->availableBalance(),
                "currency"          => $a->currency,
                "status"            => $a->status,
            ]),
        ]);
    }

    // ── Registrar nuevo adelanto ───────────────────────────────────────
    // Crea el comprobante SUNAT del adelanto (sale con type='advance') y el
    // registro advances asociado, dentro de la misma transacción. El envío
    // real a SUNAT sigue siendo manual (POST /enviarSunat), igual que una
    // venta normal — ver memoria "envío SUNAT manual".
    public function store(Request $request)
    {
        $request->validate([
            'client_id'      => 'required|integer|exists:clients,id',
            'amount'         => 'required|numeric|min:0.01',
            'currency'       => 'required|string|in:PEN,USD',
            'payment_method' => 'required|string',
            // Tier 1 (hallazgo de auditoría, 2026-08-21): antes fijo
            // gravado 18% siempre, sin selector — un adelanto de cliente
            // Amazonía o de un servicio exonerado no tenía forma de salir
            // clasificado correctamente desde su propio comprobante.
            // Catálogo 07 SUNAT: '10' gravado, '20' exonerado, '30' inafecto.
            'tip_afe_igv'    => 'required|string|in:10,20,30',
            'notes'          => 'nullable|string',
            // Tier 3 (2026-08-24): referencia de pago (N° operación, banco,
            // últimos dígitos) — para medios no efectivo, sin esto no
            // quedaba ningún dato para conciliar contra el estado de cuenta
            // real del banco/Yape/Plin.
            'payment_reference' => 'nullable|string|max:255',
        ]);

        // Módulo Caja — Fase 6 (mismo patrón de guard que SaleController::
        // validarPagosPayload()/resolverSesionCajaAbierta(), Fase 3). A
        // diferencia de una venta, un adelanto SIEMPRE se cobra al
        // recibirse — no existe "adelanto sin pago inmediato" — así que acá
        // el guard es incondicional, no "si hay payments[]". Corre ANTES de
        // abrir la transacción, para no dejar nada a medio persistir.
        $metodoPagoTexto = trim($request->payment_method);
        $metodoPago = PaymentMethod::whereRaw('LOWER(code) = ?', [strtolower($metodoPagoTexto)])
            ->where('is_active', true)
            ->first();

        if (!$metodoPago) {
            throw new HttpException(422, "Método de pago no válido o inactivo: \"{$metodoPagoTexto}\".");
        }

        $sesionCaja = CashSession::where('opened_by', auth('api')->user()->id)
            ->where('status', 'open')
            ->first();

        if (!$sesionCaja) {
            throw new HttpException(
                422,
                'No hay una sesión de caja abierta. Debes abrir caja antes de registrar un adelanto.'
            );
        }

        $cliente = Client::findOrFail($request->client_id);
        $amount  = round((float) $request->amount, 2);

        try {
            DB::beginTransaction();

            $venta = $this->crearComprobanteAdelanto($cliente, $amount, $request->currency, $request->tip_afe_igv, $request->notes);

            // sale_payments no tiene columna user_id pese a que el modelo la
            // lista en $fillable (drift de esquema — ver SaleController::store(),
            // que tampoco la pasa).
            SalePayment::create([
                "sale_id"        => $venta->id,
                "method_payment" => $request->payment_method,
                "amount"         => $amount,
                "date_payment"   => now()->toDateString(),
                "comments"       => $request->payment_reference,
            ]);

            $adelanto = Advance::create([
                "client_id"      => $cliente->id,
                "sale_id"        => $venta->id,
                "amount"         => $amount,
                "currency"       => $request->currency,
                "payment_method" => $request->payment_method,
                "notes"          => $request->notes,
            ]);

            // Módulo Caja — Fase 6. $metodoPago/$sesionCaja ya resueltos y
            // validados antes de abrir esta transacción.
            CashMovement::create([
                "cash_session_id"   => $sesionCaja->id,
                "type"              => "advance_received",
                "payment_method_id" => $metodoPago->id,
                "direction"         => "in",
                "amount"            => $amount,
                "reference_type"    => "advance",
                "reference_id"      => $adelanto->id,
                "status"            => "confirmed",
                "created_by"        => auth('api')->user()->id,
            ]);

            DB::commit();
        } catch (\Throwable $error) {
            DB::rollBack();
            throw new HttpException(500, $error->getMessage());
        }

        return response()->json([
            "code"       => 200,
            "message"    => "Adelanto registrado exitosamente",
            "advance_id" => $adelanto->id,
            "sale_id"    => $venta->id,
        ]);
    }

    // ── Crea el comprobante SUNAT del adelanto (Sale + SaleDetail) ──────
    // Extraído de store() (Tier 2, hallazgo de auditoría 2026-08-21) para
    // que corregir() lo reuse sin volver a cobrar nada — corregir un
    // comprobante ya aceptado no debe generar un SalePayment/CashMovement
    // nuevo, la plata ya entró una sola vez. store() sigue siendo el único
    // lugar que cobra de verdad (guard de caja + SalePayment + CashMovement,
    // fuera de este método).
    private function crearComprobanteAdelanto(Client $cliente, float $amount, string $currency, string $tipAfeIgv, ?string $notes): Sale
    {
        $producto = Product::where('sku', self::SKU_PRODUCTO_ADELANTO)->first();
        if (!$producto) {
            throw new HttpException(500, 'No se encontró el producto especial de adelantos. Revisa la migración 2026_07_11_100004_seed_advance_special_product.');
        }

        $serieResuelta = $this->resolverSerieComprobanteAdelanto($cliente, $currency);
        $destino = $cliente->es_amazonia ? 'amazonia' : 'nacional';

        // amount = monto recibido, IGV incluido. Tratamiento tributario
        // elegido por el usuario (Tier 1) — '10' gravado 18%, '20'/'30'
        // exonerado/inafecto sin IGV. destino queda informativo/automático,
        // independiente del selector (que ya es la fuente de verdad acá).
        $porcentajeIgv = $tipAfeIgv === '10' ? 18.0 : 0.0;
        $subtotal      = round($amount / (1 + $porcentajeIgv / 100), 2);
        $igv           = round($amount - $subtotal, 2);

        $venta = Sale::create([
            "type"                 => "advance",
            "date"                 => now()->toDateString(),
            "serie"                   => $serieResuelta['serie']->serie,
            "tipo_comprobante_codigo" => $serieResuelta['tipo']->codigo,
            "serie_comprobante_id"    => $serieResuelta['serie']->id,
            // Sin esto quedaba null — rompía Sale::siguienteNumeroTransaccion()
            // para la siguiente venta real si el adelanto era la fila más
            // reciente (ver incidente 2026-07-12, memoria del proyecto).
            "n_transaction"        => Sale::siguienteNumeroTransaccion(),
            "user_id"              => auth('api')->user()->id,
            "client_id"            => $cliente->id,
            "type_client"          => $cliente->type_client,
            "cod_tipo_doc_cliente" => $cliente->cod_tipo_doc_sunat,
            "currency"             => $currency,
            "is_exportacion"       => 0,
            "destino"              => $destino,
            "type_payment"         => 1, // contado — el adelanto siempre se cobra al recibirse
            "subtotal"             => $subtotal,
            "igv"                  => $igv,
            "total"                => $amount,
            "discount"             => 0,
            "discount_global"      => 0,
            "mto_oper_gravadas"    => $tipAfeIgv === '10' ? $subtotal : 0,
            "mto_oper_exoneradas"  => $tipAfeIgv === '20' ? $subtotal : 0,
            "mto_oper_inafectas"   => $tipAfeIgv === '30' ? $subtotal : 0,
            "mto_oper_exportacion" => 0,
            "mto_oper_gratuitas"   => 0,
            "isc_total"            => 0,
            "icbper_total"         => 0,
            "ivap_total"           => 0,
            "total_impuestos"      => $igv,
            "valor_venta"          => $subtotal,
            "mto_imp_venta"        => $amount,
            "redondeo"             => 0,
            "retencion_igv"        => 0,
            "monto_retencion"      => 0,
            "monto_detraccion"     => 0,
            "monto_percepcion"     => 0,
            "state_payment"        => 3, // pagado — el adelanto se cobra íntegro al crearse
            "debt"                 => 0,
            "paid_out"             => $amount,
            "description"          => trim('Adelanto a cuenta - venta futura' . ($notes ? ' — ' . $notes : '')),
        ]);

        SaleDetail::create([
            "sale_id"              => $venta->id,
            "product_id"           => $producto->id,
            "product_categorie_id" => $producto->categorie_id,
            "unidad_medida"        => $producto->unidad_medida,
            "quantity"             => 1,
            "price_base"           => $subtotal,
            "price_final"          => $amount,
            "discount"             => 0,
            "subtotal"             => $subtotal,
            "mto_valor_venta"      => $subtotal,
            "mto_base_igv"         => $subtotal,
            "porcentaje_igv"       => $porcentajeIgv,
            "igv"                  => $igv,
            "tip_afe_igv"          => $tipAfeIgv,
            "total_impuestos"      => $igv,
            "description"          => $producto->title,
            // Sin ISC/ICBPER — el adelanto no es un bien físico. Se
            // defaultean explícitamente (no se omiten): note_details,
            // a diferencia de sale_details, tiene percentage_isc/isc
            // NOT NULL, y clonarLineasTotal() copia estos valores tal
            // cual al reembolsar (ver AdvanceController::refund()).
            "percentage_isc"       => 0,
            "isc"                  => 0,
            "tipo_isc"             => null,
            "monto_isc_fijo"       => 0,
            "per_icbper"           => 0,
            "icbper"               => 0,
        ]);

        return $venta;
    }

    // ── Reembolsar saldo de un adelanto ────────────────────────────────
    // v1 solo cubre el caso ya soportado por el sistema: adelanto NUNCA
    // aplicado, reembolso del 100% → Nota de Crédito motivo '06'
    // (Devolución total, Catálogo 09 SUNAT). Reembolso parcial o de un
    // adelanto ya aplicado en parte requiere motivo '09' (Disminución en
    // el valor), que se implementa en otra sesión — ver plan, sección 0 y
    // 3. Este endpoint queda como el punto de integración: cuando NC 09
    // exista, solo hay que reemplazar la rama 501 de abajo.
    public function refund(Request $request, string $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string',
        ]);

        $monto = round((float) $request->amount, 2);

        // ── lockForUpdate() desde el inicio de la transacción ───────────
        // Antes se leía el adelanto sin lock: dos solicitudes de reembolso
        // simultáneas sobre el mismo adelanto podían ambas pasar la
        // validación de saldo (refunded_amount solo se actualiza cuando la
        // NC es ACEPTADA por SUNAT, no acá al crearla) y crear dos Notas de
        // Crédito totales antes de que cualquiera se persista. Ahora todo
        // — relectura, validación de saldo/estado, y creación de la NC —
        // corre bajo el mismo lock, misma transacción (hallazgo de
        // auditoría del módulo, 2026-08-21).
        $nota = DB::transaction(function () use ($id, $monto, $request) {
            $adelanto = Advance::where('id', $id)->lockForUpdate()->firstOrFail();
            $saldo    = $adelanto->availableBalance();

            if ($monto > $saldo) {
                throw new HttpException(
                    422,
                    "El monto a reembolsar (S/ " . number_format($monto, 2) . ") supera el saldo " .
                    "disponible del adelanto (S/ " . number_format($saldo, 2) . ")."
                );
            }

            $nuncaAplicado    = (float) $adelanto->applied_amount == 0.0;
            $esReembolsoTotal = round($monto, 2) === round((float) $adelanto->amount, 2);

            if (!$nuncaAplicado || !$esReembolsoTotal) {
                throw new HttpException(
                    501,
                    "Reembolso parcial, o de un adelanto ya aplicado en parte, todavía no " .
                    "está implementado (requiere Nota de Crédito motivo 09 — pendiente de " .
                    "otra sesión). Este adelanto solo puede reembolsarse al 100% mientras " .
                    "no tenga ningún monto aplicado."
                );
            }

            // ── Crear la NC motivo 06 (Devolución total) ────────────────
            // Reutiliza NotaElectronicaController::store() (valida que el
            // comprobante del adelanto ya esté aceptado por SUNAT, arma las
            // líneas clonando el detalle completo de la venta, calcula
            // totales) — mismo criterio de reutilización que el resto del
            // módulo. reponer_stock=false: el producto especial de adelantos
            // no representa inventario real.
            $notaRequest = new Request([
                'sale_id'         => $adelanto->sale_id,
                'tipo_doc'        => '07',
                'cod_motivo'      => '06',
                'tipo_afectacion' => 'total',
                'reponer_stock'   => false,
            ]);

            $notaResponse = app(NotaElectronicaController::class)->store($notaRequest);
            $notaData     = json_decode($notaResponse->getContent(), true);
            $nota         = Note::findOrFail($notaData['note']['id']);

            AdvanceRefund::create([
                "advance_id"      => $adelanto->id,
                "note_id"         => $nota->id,
                "amount_refunded" => $monto,
                "reason"          => $request->reason,
            ]);

            // refunded_amount/status del adelanto se actualizan recién
            // cuando esta nota sea ACEPTADA por SUNAT — ver
            // NotaElectronicaController::enviarNotaSunat(). Una NC creada
            // pero rechazada (o nunca enviada) no debe dejar el adelanto
            // marcado como reembolsado.

            return $nota;
        });

        return response()->json([
            "code"    => 200,
            "message" => "Nota de Crédito creada. Debe enviarse a SUNAT (POST /notas/enviar-sunat) " .
                         "para completar el reembolso.",
            "note"    => $nota,
        ]);
    }

    // ── Corregir tratamiento tributario de un adelanto ya aceptado ──────
    // Tier 2 (hallazgo de auditoría, 2026-08-21): un comprobante ya
    // aceptado por SUNAT es inmutable — la única corrección posible es
    // anular (NC motivo 01, "Anulación de la operación") + reemitir con el
    // dato correcto. Alcance deliberadamente angosto: SOLO cambia
    // tip_afe_igv. client_id/amount son el ancla de AdvanceApplicationService
    // y de cualquier AdvanceApplication ya existente — cambiarlos
    // retroactivamente invalidaría aplicaciones ya hechas. payment_method es
    // un hecho histórico (ya vive en SalePayment/CashMovement, que nunca se
    // tocan acá) — no aparece en el XML/PDF del comprobante, no hay nada que
    // "corregir" ahí. Sin guard de "ya aplicado": el bloque PrepaidPayment de
    // Greenter (ver GreenterService::getInvoice()) solo lleva tipoDocRel/
    // nroDocRel/total, nunca la clasificación tributaria del adelanto — así
    // que corregir el documento fiscal no toca ninguna venta final que ya lo
    // haya consumido.
    public function corregir(Request $request, string $id)
    {
        $request->validate([
            'tip_afe_igv'       => 'required|string|in:10,20,30',
            'motivo_correccion' => 'required|string|min:10',
        ]);

        $adelanto = Advance::with('sale', 'client')->findOrFail($id);

        if (!$adelanto->sale || !$adelanto->sale->xml || !$adelanto->sale->cdr) {
            throw new HttpException(
                422,
                'Este adelanto todavía no fue aceptado por SUNAT — no hay nada que corregir todavía ' .
                '(envíalo primero desde esta misma pantalla).'
            );
        }

        $ventaAnteriorId = $adelanto->sale_id;

        DB::transaction(function () use ($request, $adelanto, $ventaAnteriorId) {
            // ── Anular el comprobante viejo (NC motivo 01) ──────────────
            // Misma reutilización que refund() ya hace para motivo 06 —
            // NO se envía a SUNAT automáticamente acá, mismo patrón de dos
            // pasos que ya tiene todo el módulo (se envía después desde
            // show.vue).
            $notaRequest = new Request([
                'sale_id'         => $ventaAnteriorId,
                'tipo_doc'        => '07',
                'cod_motivo'      => '01',
                'tipo_afectacion' => 'total',
                'reponer_stock'   => false,
            ]);
            app(NotaElectronicaController::class)->store($notaRequest);

            // ── Reemitir con el tratamiento correcto ────────────────────
            $ventaNueva = $this->crearComprobanteAdelanto(
                $adelanto->client,
                (float) $adelanto->amount,
                $adelanto->currency,
                $request->tip_afe_igv,
                $adelanto->notes
            );

            // Mismo Advance.id — preserva AdvanceApplication/historial
            // intactos, solo cambia qué comprobante lo respalda.
            $adelanto->update([
                'sale_id'                => $ventaNueva->id,
                'corrected_from_sale_id' => $ventaAnteriorId,
                'correction_reason'      => $request->motivo_correccion,
                'corrected_at'           => now(),
                'corrected_by'           => auth('api')->user()->id,
            ]);
        });

        return response()->json([
            "code"    => 200,
            "message" => "Adelanto corregido. La Nota de Crédito de anulación y el comprobante nuevo " .
                         "deben enviarse a SUNAT para completar la corrección.",
            "advance" => $adelanto->fresh(['sale', 'correctedFromSale']),
        ]);
    }
}
