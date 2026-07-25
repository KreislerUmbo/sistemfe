<?php

namespace App\Http\Controllers\Credit;

use App\Http\Controllers\Controller;
use App\Models\Client\Client;
use App\Models\Company;
use App\Models\Credit\PaymentReceipt;
use App\Models\Sale\SalePayment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §3.10/§4, pendientes
// dejados fuera del cierre del módulo (Fase 9): historial de recibos de pago
// y su PDF (documento interno, NO comprobante SUNAT — sin QR fiscal).
// Separado de CreditPaymentController (mutación de estado financiero: preview/
// store/anular/refund/replace) porque estos métodos son de solo lectura/
// presentación, misma separación que ya existe entre CreditPaymentController
// y CreditReceivablesController.
class PaymentReceiptController extends Controller
{
    // ── GET /clients/{client}/payment-receipts ──────────────────────────
    // Historial de un cliente. Combina DOS orígenes de pago que en este
    // sistema nunca se unificaron (bug real reportado 2026-07-21, cliente
    // de la venta #37): el pago inicial de una venta (contado o el
    // adelanto de una venta a crédito) se guarda como SalePayment simple
    // desde SaleController::store()/SalePaymentController — NUNCA genera
    // un PaymentReceipt, que solo se crea al cobrar desde Cuentas por
    // Cobrar (CreditPaymentController::store()). Sin este merge, el
    // historial mostraba "sin recibos" para un cliente que sí pagó algo.
    // Paginación en memoria (mismo patrón que
    // CreditReceivablesController::paginar()) porque la fuente ya no es
    // una sola tabla — no hay forma de paginar esto con Eloquent solo.
    public function index(Client $client, Request $request)
    {
        $recibosQuery = $client->paymentReceipts()
            ->with(['applications.sale:id,n_operacion', 'applications.installment:id,numero_cuota']);

        if ($request->filled('estado')) {
            $recibosQuery->where('estado', $request->estado);
        }
        if ($request->filled('medio_pago')) {
            $recibosQuery->where('medio_pago', $request->medio_pago);
        }
        if ($request->filled('fecha_desde')) {
            $recibosQuery->whereDate('fecha_pago', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $recibosQuery->whereDate('fecha_pago', '<=', $request->fecha_hasta);
        }

        $recibos = $recibosQuery->get()->map(fn ($r) => $this->formatearReceipt($r));

        $pagosVentaQuery = SalePayment::whereHas('sale', fn ($q) => $q->where('client_id', $client->id))
            ->with('sale:id,n_operacion,serie,correlativo,total');

        if ($request->filled('medio_pago')) {
            $pagosVentaQuery->where('method_payment', $request->medio_pago);
        }
        if ($request->filled('fecha_desde')) {
            $pagosVentaQuery->whereDate('date_payment', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $pagosVentaQuery->whereDate('date_payment', '<=', $request->fecha_hasta);
        }
        // 'estado' es un filtro propio de payment_receipts (confirmado/
        // anulado) — un SalePayment no tiene ese concepto, así que si el
        // usuario filtra por estado, los pagos de venta simplemente no
        // aplican (se excluyen, no se listan sin filtrar).
        $pagosVenta = $request->filled('estado')
            ? collect()
            : $pagosVentaQuery->get()->map(fn ($p) => $this->formatearPagoVenta($p));

        $historial = $recibos->concat($pagosVenta)
            ->sortByDesc(fn ($item) => $item['fecha_pago'] . ' ' . ($item['created_at'] ?? ''))
            ->values();

        $page = (int) ($request->page ?? 1);

        return response()->json([
            'client_id' => $client->id,
            'total' => $historial->count(),
            'paginate' => 25,
            'payment_receipts' => $historial->forPage($page, 25)->values(),
        ]);
    }

    // Normaliza un SalePayment al mismo shape que formatearReceipt(), con
    // 'origen' => 'pago_venta' para que el frontend lo distinga y no
    // ofrezca acciones exclusivas de recibo (anular, PDF, aplicaciones).
    private function formatearPagoVenta(SalePayment $pago): array
    {
        return [
            'origen' => 'pago_venta',
            'id' => $pago->id,
            'numero_recibo' => null,
            'client_id' => $pago->sale->client_id ?? null,
            // date_payment es un timestamp crudo (sin cast a Carbon en el
            // modelo, a diferencia de fecha_pago en PaymentReceipt) — a
            // veces null en pagos contado (ver venta #37), por eso el
            // fallback a created_at.
            'fecha_pago' => \Carbon\Carbon::parse($pago->date_payment ?? $pago->created_at)->format('Y-m-d'),
            'medio_pago' => $pago->method_payment,
            'nro_operacion' => null,
            'monto_total' => (float) $pago->amount,
            'monto_no_aplicado' => 0,
            'estado' => 'activo',
            'motivo_anulacion' => null,
            'anulado_en' => null,
            'created_at' => $pago->created_at?->format('Y-m-d H:i:s'),
            'sale_id' => $pago->sale_id,
            'sale_n_operacion' => $pago->sale->n_operacion
                ?? ($pago->sale->serie . '-' . $pago->sale->correlativo),
            'applications' => [],
        ];
    }

    // Igual que CreditInstallmentController::formatearInstallment(): el cast
    // Eloquent 'date' solo no basta, serializa como ISO 8601 completo
    // ("2026-07-16T00:00:00.000000Z") aunque fecha_pago no tenga hora real
    // (se captura con un <input type="date">, sin selector de hora) —
    // confunde al frontend, que lo mostraba tal cual. Se arma un array
    // explícito con fechas ya formateadas en vez de devolver el modelo crudo.
    private function formatearReceipt(PaymentReceipt $receipt): array
    {
        return [
            'origen' => 'recibo',
            'id' => $receipt->id,
            'numero_recibo' => $receipt->numero_recibo,
            'client_id' => $receipt->client_id,
            'fecha_pago' => $receipt->fecha_pago?->format('Y-m-d'),
            'medio_pago' => $receipt->medio_pago,
            'nro_operacion' => $receipt->nro_operacion,
            'monto_total' => (float) $receipt->monto_total,
            'monto_no_aplicado' => (float) $receipt->monto_no_aplicado,
            'estado' => $receipt->estado,
            'motivo_anulacion' => $receipt->motivo_anulacion,
            'anulado_en' => $receipt->anulado_en?->format('Y-m-d H:i:s'),
            'created_at' => $receipt->created_at?->format('Y-m-d H:i:s'),
            'sale_id' => null,
            'sale_n_operacion' => null,
            'applications' => $receipt->applications->map(fn ($a) => [
                'id' => $a->id,
                'payment_receipt_id' => $a->payment_receipt_id,
                'sale_id' => $a->sale_id,
                'installment_id' => $a->installment_id,
                'monto_aplicado' => (float) $a->monto_aplicado,
                'monto_mora_cobrado' => (float) $a->monto_mora_cobrado,
                'orden_aplicacion' => $a->orden_aplicacion,
                'estado' => $a->estado,
                'refund_id' => $a->refund_id,
                'origen_application_id' => $a->origen_application_id,
                'sale' => $a->sale ? ['id' => $a->sale->id, 'n_operacion' => $a->sale->n_operacion] : null,
                'installment' => $a->installment ? ['id' => $a->installment->id, 'numero_cuota' => $a->installment->numero_cuota] : null,
            ])->values(),
        ];
    }

    // ── GET /payment-receipts-pdf-url/{id} ───────────────────────────────
    // Autenticado (dentro de auth:api). Genera una URL firmada de 10 min
    // contra la ruta pública 'payment-receipts.pdf' — mismo motivo que
    // SaleController::pdfSignedUrl(): el frontend abre el PDF con
    // window.open(), que no puede llevar el header Authorization.
    public function pdfSignedUrl(Request $request, string $id)
    {
        PaymentReceipt::findOrFail($id);

        $formato = $request->query('format');
        if (!in_array($formato, ['a4', 'ticket80mm'])) {
            // A diferencia de Sale (formato del vendedor de la venta), acá el
            // usuario relevante es quien está imprimiendo AHORA, no
            // registrado_por (puede ser otro cajero de otro turno).
            $formato = auth('api')->user()->formato_impresion_default ?? 'a4';
        }

        $url = URL::temporarySignedRoute('payment-receipts.pdf', now()->addMinutes(10), [
            'id' => $id,
            'format' => $formato,
        ]);

        return response()->json(['url' => $url]);
    }

    // ── GET /payment-receipts-pdf/{id} ───────────────────────────────────
    // Solo accesible vía URL firmada temporal (middleware 'signed' en la
    // ruta, fuera del grupo auth:api). Sin QrCodeService/$qr — es documento
    // interno de control, no lleva QR fiscal SUNAT.
    public function pdf(string $id, Request $request)
    {
        $receipt = PaymentReceipt::with([
            'client',
            'registradoPor',
            'anuladoPor',
            'applications' => fn ($q) => $q->orderBy('orden_aplicacion'),
            'applications.sale:id,n_operacion,saldo_pendiente',
            'applications.installment:id,numero_cuota',
        ])->find($id);

        if (!$receipt) {
            return abort(404);
        }

        $formato = $request->query('format');
        if (!in_array($formato, ['a4', 'ticket80mm'])) {
            $formato = 'a4';
        }

        $empresa = Company::first();
        $vista = $formato === 'ticket80mm' ? 'pdf.recibo_pago_ticket80mm' : 'pdf.recibo_pago_a4';

        $pdf = Pdf::loadView($vista, compact('receipt', 'empresa'));

        if ($formato === 'ticket80mm') {
            $alto_estimado = 420 + ($receipt->applications->count() * 30);
            $pdf->setPaper([0, 0, 226.77, $alto_estimado], 'portrait');
        } else {
            $pdf->setPaper('a4', 'portrait');
        }

        return $pdf->stream('recibo_' . $receipt->numero_recibo . '.pdf');
    }
}
