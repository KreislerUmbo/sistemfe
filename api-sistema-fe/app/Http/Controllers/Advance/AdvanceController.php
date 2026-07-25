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
    public function index()
    {
        $adelantos = Advance::with(['client', 'sale'])
            ->orderBy('id', 'desc')
            ->paginate(25);

        return response()->json([
            "total"     => $adelantos->total(),
            "paginate"  => 25,
            "advances"  => $adelantos->items(),
        ]);
    }

    // ── Detalle de un adelanto ─────────────────────────────────────────
    public function show(string $id)
    {
        $adelanto = Advance::with(['client', 'sale', 'applications.sale', 'refunds.note'])
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
            'notes'          => 'nullable|string',
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

        $producto = Product::where('sku', self::SKU_PRODUCTO_ADELANTO)->first();
        if (!$producto) {
            throw new HttpException(500, 'No se encontró el producto especial de adelantos. Revisa la migración 2026_07_11_100004_seed_advance_special_product.');
        }

        $serieResuelta = $this->resolverSerieComprobanteAdelanto($cliente, $request->currency);
        $destino = $cliente->es_amazonia ? 'amazonia' : 'nacional';

        // amount = monto recibido, IGV incluido. Tratamiento fijo gravado
        // 18% (tip_afe_igv_default del producto especial) — no se recalcula
        // contra destino/exportación del cliente. Ver comentario en la
        // migración del producto especial: pendiente de confirmar con
        // contador si un adelanto de cliente Amazonía debe salir exonerado
        // desde su propio comprobante.
        $porcentajeIgv = 18.0;
        $amount        = round((float) $request->amount, 2);
        $subtotal      = round($amount / (1 + $porcentajeIgv / 100), 2);
        $igv           = round($amount - $subtotal, 2);

        try {
            DB::beginTransaction();

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
                "currency"             => $request->currency,
                "is_exportacion"       => 0,
                "destino"              => $destino,
                "state_sale"           => 1, // venta (no cotización)
                "type_payment"         => 1, // contado — el adelanto siempre se cobra al recibirse
                "subtotal"             => $subtotal,
                "igv"                  => $igv,
                "total"                => $amount,
                "discount"             => 0,
                "discount_global"      => 0,
                "mto_oper_gravadas"    => $subtotal,
                "mto_oper_exoneradas"  => 0,
                "mto_oper_inafectas"   => 0,
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
                "description"          => trim('Adelanto a cuenta - venta futura' . ($request->notes ? ' — ' . $request->notes : '')),
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
                "tip_afe_igv"          => (string) $producto->tip_afe_igv_default,
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

            // sale_payments no tiene columna user_id pese a que el modelo la
            // lista en $fillable (drift de esquema — ver SaleController::store(),
            // que tampoco la pasa).
            SalePayment::create([
                "sale_id"        => $venta->id,
                "method_payment" => $request->payment_method,
                "amount"         => $amount,
                "date_payment"   => now()->toDateString(),
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

        $adelanto = Advance::findOrFail($id);
        $monto    = round((float) $request->amount, 2);
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
            return response()->json([
                "code"    => 501,
                "message" => "Reembolso parcial, o de un adelanto ya aplicado en parte, todavía no " .
                             "está implementado (requiere Nota de Crédito motivo 09 — pendiente de " .
                             "otra sesión). Este adelanto solo puede reembolsarse al 100% mientras " .
                             "no tenga ningún monto aplicado.",
            ], 501);
        }

        // ── Crear la NC motivo 06 (Devolución total) ────────────────────
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

        $nota = DB::transaction(function () use ($notaRequest, $adelanto, $monto, $request) {
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
}
