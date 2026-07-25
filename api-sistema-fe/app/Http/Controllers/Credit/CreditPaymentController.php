<?php

namespace App\Http\Controllers\Credit;

use App\Http\Controllers\Controller;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Models\Credit\Installment;
use App\Models\Credit\PaymentApplication;
use App\Models\Credit\PaymentReceipt;
use App\Models\Credit\PaymentRefund;
use App\Models\Sale\Sale;
use App\Services\CashCorrectionService;
use App\Services\CreditPaymentAllocator;
use App\Services\CreditSummaryCalculator;
use App\Services\MoraCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §3.2, §3.3, §3.4, §3.8, §4.
// Un solo payload/endpoint cubre caso general (N ventas) y caso específico
// (1 venta, vía sale_id en el preview) — el específico es el trivial N=1
// del mismo algoritmo (§3.3), no hay ruta separada.
//
// Mora (Fase 6): calculada on-the-fly por CreditPaymentAllocator/
// MoraCalculator contra fecha_pago, congelada en
// payment_applications.monto_mora_cobrado recién al confirmar el pago
// (store()) — preview() solo la muestra, no persiste nada.
//
// Fuera de alcance de esta fase (documentado, no implementado en silencio):
// - installments.estado='vencida' NO se setea acá — es una transición
//   pasiva (job nocturno / cálculo on-the-fly, §3.8), no algo que dispare
//   un pago. Este controlador solo transiciona pendiente -> parcial ->
//   pagada según cobertura real (de CAPITAL — mora no tiene estado propio).
class CreditPaymentController extends Controller
{
    protected $allocator;
    protected $moraCalculator;
    protected $summaryCalculator;
    protected $cashCorrection;

    public function __construct(
        CreditPaymentAllocator $allocator,
        MoraCalculator $moraCalculator,
        CreditSummaryCalculator $summaryCalculator,
        CashCorrectionService $cashCorrection
    ) {
        $this->allocator = $allocator;
        $this->moraCalculator = $moraCalculator;
        $this->summaryCalculator = $summaryCalculator;
        $this->cashCorrection = $cashCorrection;
    }

    // ── GET /clients/{client}/credit-summary ─────────────────────────────
    // §4/§3.2 paso 1 — estado de cuenta consolidado: deuda total, por
    // venta, cuotas vencidas, mora acumulada. Misma fuente de datos que el
    // resumen "deuda actual" que se muestra siempre al abrir la pantalla de
    // pago de un cliente. Cálculo por venta delegado a
    // CreditSummaryCalculator (compartido con CreditReceivablesController,
    // §3.11) para no duplicar la lógica de estado/mora/cuotas vencidas.
    public function creditSummary(Client $client)
    {
        $ventas = $this->summaryCalculator->ventasAbiertasCliente($client->id);

        $ventasResumen = $ventas->map(fn ($venta) => $this->summaryCalculator->resumenVenta($venta))->values();

        return response()->json([
            'client_id' => $client->id,
            'client_nombre' => $client->full_name,
            'saldo_a_favor' => (float) $client->saldo_a_favor,
            'deuda_total' => round($ventasResumen->sum('saldo_pendiente'), 2),
            'mora_acumulada_total' => round($ventasResumen->sum('mora_acumulada'), 2),
            'cantidad_ventas_abiertas' => $ventasResumen->count(),
            'cantidad_cuotas_vencidas_total' => $ventasResumen->sum('cuotas_vencidas'),
            'ventas' => $ventasResumen,
        ]);
    }

    // ── POST /clients/{client}/payments/preview ─────────────────────────
    // No persiste nada. Corre CreditPaymentAllocator::allocate() — mismo
    // criterio que CreditInstallmentController::preview() con el service
    // de Fase 3 (el frontend ve montos en vivo sin reimplementar el
    // redondeo en TS).
    public function preview(Request $request, Client $client)
    {
        $request->validate([
            'monto_total' => 'required|numeric|min:0.01',
            'fecha_pago' => 'required|date',
            'medio_pago' => 'required|string',
            'nro_operacion' => 'nullable|string',
            'sale_id' => 'nullable|integer|exists:sales,id', // caso específico (§3.3)
        ]);

        $resultado = $this->allocator->allocate(
            $client,
            (float) $request->monto_total,
            $request->filled('sale_id') ? (int) $request->sale_id : null,
            Carbon::parse($request->fecha_pago)
        );

        return response()->json(array_merge(['client_id' => $client->id], $resultado));
    }

    // ── POST /clients/{client}/payments ──────────────────────────────────
    // Input: el array 'aplicaciones' ya confirmado/editado por el cajero.
    // Shape de cada fila: {sale_id, installment_id (nullable),
    // monto_aplicado, monto_mora_aplicado (nullable, default 0)} — a
    // propósito NO se aceptan saldo_antes/saldo_despues/mora_antes del
    // payload (esos son solo output informativo del preview): se
    // recalculan acá, bajo lock, contra el estado real de la BD. Nunca se
    // confía en aritmética que vino del cliente para algo que se persiste.
    //
    // monto_no_aplicado NO es un input tampoco — se deriva siempre server-
    // side como monto_total - suma(monto_aplicado + monto_mora_aplicado).
    // Esto responde directo la pregunta de "qué pasa si el cajero excluye
    // una venta del reparto": el payload NO necesita venir cuadrado, el
    // backend asume que cualquier remanente no repartido es sobrepago
    // automático → se acredita a client.saldo_a_favor (§3.7). La única
    // validación dura es que la suma (capital+mora) no supere monto_total.
    //
    // monto_mora_aplicado (Fase 6) se valida contra la mora calculada
    // on-the-fly (MoraCalculator) a la fecha_pago del request — mismo
    // criterio "nunca confiar en el cliente" que monto_aplicado. Se
    // congela en payment_applications.monto_mora_cobrado — eso sí es
    // inmutable una vez pagado (§3.8).
    public function store(Request $request, Client $client)
    {
        $request->validate([
            'monto_total' => 'required|numeric|min:0.01',
            'fecha_pago' => 'required|date',
            'medio_pago' => 'required|string',
            'nro_operacion' => 'nullable|string',
            'aplicaciones' => 'present|array',
            'aplicaciones.*.sale_id' => 'required|integer|exists:sales,id',
            'aplicaciones.*.installment_id' => 'nullable|integer|exists:installments,id',
            // min:0 (no 0.01) a propósito: con mora-primero-luego-capital,
            // un pago chico contra una cuota muy vencida puede consumirse
            // ENTERO en mora, dejando monto_aplicado=0 en esa fila — el
            // preview ya puede devolver eso, store() tiene que poder
            // persistirlo. Se valida "algo > 0 en la fila" más abajo.
            'aplicaciones.*.monto_aplicado' => 'required|numeric|min:0',
            'aplicaciones.*.monto_mora_aplicado' => 'nullable|numeric|min:0',
        ]);

        $fechaPago = Carbon::parse($request->fecha_pago);

        // Módulo Caja — Fase 6 (mismo patrón de guard que SaleController::
        // resolverSesionCajaAbierta()/AdvanceController::store()). Un cobro
        // de cuota SIEMPRE es dinero recibido ahora — no existe "cobro sin
        // caja" en este endpoint — así que el guard es incondicional. Corre
        // ANTES de abrir la transacción, DB::transaction() re-lanza
        // cualquier excepción tal cual (no la convierte en 500), pero según
        // el mismo criterio ya usado en Fase 3, la validación que no
        // necesita locks se resuelve afuera.
        $medioPagoTexto = trim($request->medio_pago);
        $medioPago = PaymentMethod::whereRaw('LOWER(code) = ?', [strtolower($medioPagoTexto)])
            ->where('is_active', true)
            ->first();

        if (!$medioPago) {
            abort(422, "Método de pago no válido o inactivo: \"{$medioPagoTexto}\".");
        }

        $sesionCaja = CashSession::where('opened_by', auth('api')->id())
            ->where('status', 'open')
            ->first();

        if (!$sesionCaja) {
            abort(422, 'No hay una sesión de caja abierta. Debes abrir caja antes de registrar un cobro.');
        }

        return DB::transaction(function () use ($request, $client, $fechaPago, $medioPago, $sesionCaja) {
            $aplicacionesInput = collect($request->aplicaciones);

            $montoTotalCentavos = (int) round((float) $request->monto_total * 100);
            $sumaAplicadoCentavos = (int) $aplicacionesInput->sum(
                fn ($a) => (int) round((float) $a['monto_aplicado'] * 100)
            );
            $sumaMoraCentavos = (int) $aplicacionesInput->sum(
                fn ($a) => (int) round((float) ($a['monto_mora_aplicado'] ?? 0) * 100)
            );
            $sumaTotalCentavos = $sumaAplicadoCentavos + $sumaMoraCentavos;

            if ($sumaTotalCentavos > $montoTotalCentavos) {
                abort(422, sprintf(
                    'La suma de monto_aplicado + monto_mora_aplicado (%s) no puede superar el monto_total pagado (%s).',
                    number_format($sumaTotalCentavos / 100, 2),
                    number_format($montoTotalCentavos / 100, 2)
                ));
            }

            // Locks reusados entre filas del mismo sale_id/installment_id
            // dentro del mismo payload — evita relecturas innecesarias, el
            // lock ya cubre toda la transacción.
            $ventasLock = [];
            $installmentsLock = [];
            $acumuladoPorBucket = []; // "saleId:installmentId|venta" => centavos de CAPITAL ya asignados en ESTE payload
            $acumuladoMoraPorBucket = []; // idem, para MORA

            $filasValidadas = $aplicacionesInput->map(function ($a) use ($client, $fechaPago, &$ventasLock, &$installmentsLock, &$acumuladoPorBucket, &$acumuladoMoraPorBucket) {
                $saleId = (int) $a['sale_id'];
                $installmentId = !empty($a['installment_id']) ? (int) $a['installment_id'] : null;
                $montoCentavos = (int) round((float) $a['monto_aplicado'] * 100);
                $montoMoraCentavos = (int) round((float) ($a['monto_mora_aplicado'] ?? 0) * 100);

                if ($montoCentavos <= 0 && $montoMoraCentavos <= 0) {
                    abort(422, "Una fila de 'aplicaciones' no puede tener monto_aplicado y " .
                        "monto_mora_aplicado ambos en 0.");
                }

                if (!isset($ventasLock[$saleId])) {
                    $ventasLock[$saleId] = Sale::whereKey($saleId)->lockForUpdate()->first();
                }
                $venta = $ventasLock[$saleId];

                if (!$venta || (int) $venta->client_id !== (int) $client->id) {
                    abort(422, "La venta #{$saleId} no pertenece al cliente #{$client->id}.");
                }

                $bucketKey = $saleId . ':' . ($installmentId ?? 'venta');
                $yaAsignadoEnEsteBucket = $acumuladoPorBucket[$bucketKey] ?? 0;
                $yaAsignadoMoraEnEsteBucket = $acumuladoMoraPorBucket[$bucketKey] ?? 0;

                if ($installmentId) {
                    if (!isset($installmentsLock[$installmentId])) {
                        $installmentsLock[$installmentId] = Installment::whereKey($installmentId)->lockForUpdate()->first();
                    }
                    $cuota = $installmentsLock[$installmentId];

                    if (!$cuota || (int) $cuota->sale_id !== $saleId) {
                        abort(422, "La cuota #{$installmentId} no pertenece a la venta #{$saleId}.");
                    }

                    if (!in_array($cuota->estado, ['pendiente', 'parcial', 'vencida'], true)) {
                        abort(422, "La cuota #{$installmentId} está en estado '{$cuota->estado}', no admite pagos.");
                    }

                    $saldoCapitalCentavos = $this->allocator->saldoCentavosInstallment($cuota);
                    $saldoDisponibleCentavos = $saldoCapitalCentavos - $yaAsignadoEnEsteBucket;
                    $moraDisponibleCentavos = $this->moraCalculator->calcularMoraInstallmentCentavos($cuota, $saldoCapitalCentavos, $fechaPago) - $yaAsignadoMoraEnEsteBucket;
                } else {
                    $saldoCapitalCentavos = (int) round((float) $venta->saldo_pendiente * 100);
                    $saldoDisponibleCentavos = $saldoCapitalCentavos - $yaAsignadoEnEsteBucket;
                    $moraDisponibleCentavos = $this->moraCalculator->calcularMoraVentaLibreCentavos($venta, $saldoCapitalCentavos, $fechaPago) - $yaAsignadoMoraEnEsteBucket;
                }

                if ($montoCentavos > $saldoDisponibleCentavos) {
                    abort(422, sprintf(
                        'El monto aplicado a %s (%s) supera su saldo disponible (%s).',
                        $installmentId ? "la cuota #{$installmentId}" : "la venta #{$saleId}",
                        number_format($montoCentavos / 100, 2),
                        number_format($saldoDisponibleCentavos / 100, 2)
                    ));
                }

                if ($montoMoraCentavos > $moraDisponibleCentavos) {
                    abort(422, sprintf(
                        'La mora aplicada a %s (%s) supera la mora calculada disponible (%s).',
                        $installmentId ? "la cuota #{$installmentId}" : "la venta #{$saleId}",
                        number_format($montoMoraCentavos / 100, 2),
                        number_format($moraDisponibleCentavos / 100, 2)
                    ));
                }

                $acumuladoPorBucket[$bucketKey] = $yaAsignadoEnEsteBucket + $montoCentavos;
                $acumuladoMoraPorBucket[$bucketKey] = $yaAsignadoMoraEnEsteBucket + $montoMoraCentavos;

                return [
                    'sale_id' => $saleId,
                    'installment_id' => $installmentId,
                    'monto_centavos' => $montoCentavos,
                    'mora_centavos' => $montoMoraCentavos,
                ];
            });

            $montoNoAplicadoCentavos = $montoTotalCentavos - $sumaTotalCentavos;

            // Sanity check final antes de commitear (pedido explícito, §3.4
            // punto 6) — debería ser imposible que falle dado lo anterior,
            // pero se deja como último guardián antes de escribir dinero.
            if (($sumaTotalCentavos + $montoNoAplicadoCentavos) !== $montoTotalCentavos) {
                abort(500, 'Inconsistencia de montos al armar el recibo de pago — no se persistió nada.');
            }

            $receipt = PaymentReceipt::create([
                'numero_recibo' => $this->siguienteNumeroRecibo(),
                'client_id' => $client->id,
                'fecha_pago' => $request->fecha_pago,
                'medio_pago' => $request->medio_pago,
                'nro_operacion' => $request->nro_operacion,
                'monto_total' => round($montoTotalCentavos / 100, 2),
                'monto_no_aplicado' => round($montoNoAplicadoCentavos / 100, 2),
                'registrado_por' => auth('api')->id(),
                'estado' => 'activo',
            ]);

            // Módulo Caja — Fase 6: UN cash_movement por recibo completo,
            // no uno por aplicación/cuota — un recibo es un único pago
            // físico (un medio_pago, un monto_total), aunque se reparta
            // entre varias cuotas/ventas. amount = monto_total completo
            // (incluye lo que quedó como monto_no_aplicado/saldo_a_favor —
            // ese dinero también entró físicamente a la caja).
            CashMovement::create([
                'cash_session_id' => $sesionCaja->id,
                'type' => 'installment_payment',
                'payment_method_id' => $medioPago->id,
                'direction' => 'in',
                'amount' => round($montoTotalCentavos / 100, 2),
                'reference_type' => 'payment_receipt',
                'reference_id' => $receipt->id,
                'status' => 'confirmed',
                'created_by' => auth('api')->id(),
            ]);

            $orden = 1;
            foreach ($filasValidadas as $fila) {
                PaymentApplication::create([
                    'payment_receipt_id' => $receipt->id,
                    'sale_id' => $fila['sale_id'],
                    'installment_id' => $fila['installment_id'],
                    'monto_aplicado' => round($fila['monto_centavos'] / 100, 2),
                    'monto_mora_cobrado' => round($fila['mora_centavos'] / 100, 2), // Fase 6 — congelado, ya no cambia
                    'orden_aplicacion' => $orden++,
                    'estado' => 'activo',
                ]);

                if ($fila['installment_id']) {
                    // Fase 6: con mora-primero, una fila puede tener
                    // monto_centavos=0 (todo el pago se fue a mora, nada a
                    // capital). En ese caso el capital de la cuota no
                    // cambió — NO se debe tocar estado (evita bajar
                    // 'vencida' a 'parcial' solo porque se cobró mora sin
                    // abonar nada al saldo real).
                    $cuota = $installmentsLock[$fila['installment_id']];
                    $programadoCentavos = (int) round((float) $cuota->monto_programado * 100);
                    $saldoCuotaCentavos = $this->allocator->saldoCentavosInstallment($cuota->fresh());

                    if ($saldoCuotaCentavos <= 0) {
                        $cuota->estado = 'pagada';
                        $cuota->save();
                    } elseif ($saldoCuotaCentavos < $programadoCentavos) {
                        $cuota->estado = 'parcial';
                        $cuota->save();
                    }
                }

                $venta = $ventasLock[$fila['sale_id']];
                $venta->saldo_pendiente = round(
                    ((float) $venta->saldo_pendiente * 100 - $fila['monto_centavos']) / 100,
                    2
                );
                $venta->save();
            }

            foreach ($ventasLock as $venta) {
                $this->actualizarStatePayment($venta);
            }

            if ($montoNoAplicadoCentavos > 0) {
                $client->increment('saldo_a_favor', round($montoNoAplicadoCentavos / 100, 2));
            }

            return response()->json([
                'code' => 200,
                'message' => 'Pago registrado exitosamente',
                'payment_receipt_id' => $receipt->id,
                'numero_recibo' => $receipt->numero_recibo,
                'monto_total' => (float) $receipt->monto_total,
                'monto_no_aplicado' => (float) $receipt->monto_no_aplicado,
                'total_mora_cobrada' => round($sumaMoraCentavos / 100, 2),
                'applications' => PaymentApplication::where('payment_receipt_id', $receipt->id)->get(),
            ]);
        });
    }

    // ── POST /payment-receipts/{receipt}/anular ───────────────────────────
    // §3.6 — pago erróneo de caja. payment_receipts.estado='anulado',
    // NUNCA DELETE. En cascada, dentro de la misma transacción: cada
    // payment_applications activa de este recibo pasa a 'anulada', y se
    // recalcula saldo_pendiente de cada sale afectada + estado de cada
    // installment afectada — usando SOLO las aplicaciones que sigan
    // 'activo' tras esta anulación (no simplemente revirtiendo esta fila a
    // solas: la cuota/venta puede tener otras aplicaciones activas de
    // OTROS recibos encima). Si el recibo había generado saldo_a_favor
    // (monto_no_aplicado > 0), también se revierte de client.saldo_a_favor.
    public function anular(Request $request, PaymentReceipt $receipt)
    {
        $request->validate([
            'motivo_anulacion' => 'required|string|min:5',
        ]);

        return DB::transaction(function () use ($request, $receipt) {
            $receiptLock = PaymentReceipt::whereKey($receipt->id)->lockForUpdate()->first();

            if ($receiptLock->estado === 'anulado') {
                abort(422, "El recibo {$receiptLock->numero_recibo} ya está anulado.");
            }

            // Módulo Caja — Fase 6: revierte el cash_movement del cobro
            // original ANTES de tocar el resto (fail-fast si no hay sesión
            // abierta o falta permiso de supervisor sobre sesión cerrada).
            $this->revertirCashMovementDeRecibo($receiptLock);

            $aplicacionesActivas = PaymentApplication::where('payment_receipt_id', $receiptLock->id)
                ->where('estado', 'activo')
                ->get();

            $ventasLock = [];
            $installmentsTocadas = [];

            foreach ($aplicacionesActivas as $aplicacion) {
                $saleId = $aplicacion->sale_id;

                if (!isset($ventasLock[$saleId])) {
                    $ventasLock[$saleId] = Sale::whereKey($saleId)->lockForUpdate()->first();
                }
                $venta = $ventasLock[$saleId];

                $montoCentavos = (int) round((float) $aplicacion->monto_aplicado * 100);
                $venta->saldo_pendiente = round(
                    ((int) round((float) $venta->saldo_pendiente * 100) + $montoCentavos) / 100,
                    2
                );
                $venta->save();

                $aplicacion->estado = 'anulada';
                $aplicacion->save();

                if ($aplicacion->installment_id) {
                    $installmentsTocadas[$aplicacion->installment_id] = $aplicacion->installment_id;
                }
            }

            // Recalcular cada installment tocada con lo que sigue activo
            // (podría tener otras aplicaciones de otros recibos que no se
            // tocan acá).
            foreach ($installmentsTocadas as $installmentId) {
                $cuota = Installment::whereKey($installmentId)->lockForUpdate()->first();
                if (!$cuota) {
                    continue;
                }

                // Fase 6: mismo criterio que store() — si el capital de la
                // cuota queda igual al monto_programado (nunca se le aplicó
                // capital real, solo mora en alguna de sus aplicaciones),
                // NO se toca estado. Antes de Fase 6 esto no podía pasar
                // (toda aplicación tenía capital > 0 por validación); con
                // mora-primero, una aplicación con monto_aplicado=0 es
                // válida, y revertirla no debe bajar una cuota 'vencida' a
                // 'pendiente' sin que su capital haya cambiado en absoluto.
                $programadoCentavos = (int) round((float) $cuota->monto_programado * 100);
                $saldoCentavos = $this->allocator->saldoCentavosInstallment($cuota);

                if ($saldoCentavos <= 0) {
                    $cuota->estado = 'pagada';
                    $cuota->save();
                } elseif ($saldoCentavos < $programadoCentavos) {
                    $cuota->estado = 'parcial';
                    $cuota->save();
                }
            }

            foreach ($ventasLock as $venta) {
                $this->actualizarStatePayment($venta);
            }

            if ((float) $receiptLock->monto_no_aplicado > 0) {
                $client = Client::whereKey($receiptLock->client_id)->lockForUpdate()->first();
                $montoNoAplicadoCentavos = (int) round((float) $receiptLock->monto_no_aplicado * 100);
                $saldoFavorCentavos = (int) round((float) $client->saldo_a_favor * 100);

                if ($saldoFavorCentavos < $montoNoAplicadoCentavos) {
                    // Inconsistencia de datos detectada correctamente (el
                    // cliente ya no tiene suficiente saldo_a_favor para
                    // revertir este recibo — probablemente porque algo más
                    // lo consumió entre medio), no un error de servidor:
                    // 422 (consistente con el resto del módulo) + log
                    // aparte para no mezclarla con bugs reales en los logs.
                    Log::warning('Anulación de payment_receipt bloqueada: saldo_a_favor insuficiente para revertir.', [
                        'payment_receipt_id' => $receiptLock->id,
                        'numero_recibo' => $receiptLock->numero_recibo,
                        'client_id' => $client->id,
                        'saldo_a_favor_actual' => $client->saldo_a_favor,
                        'monto_no_aplicado_a_revertir' => $receiptLock->monto_no_aplicado,
                    ]);

                    abort(422, "No se puede revertir: saldo a favor insuficiente. El cliente " .
                        "#{$client->id} tiene S/ {$client->saldo_a_favor} de saldo a favor, pero " .
                        "este recibo generó S/ {$receiptLock->monto_no_aplicado} — algo más ya lo " .
                        "consumió.");
                }

                $client->saldo_a_favor = round(($saldoFavorCentavos - $montoNoAplicadoCentavos) / 100, 2);
                $client->save();
            }

            date_default_timezone_set('America/Lima');
            $receiptLock->estado = 'anulado';
            $receiptLock->motivo_anulacion = $request->motivo_anulacion;
            $receiptLock->anulado_por = auth('api')->id();
            $receiptLock->anulado_en = now();
            $receiptLock->save();

            return response()->json([
                'code' => 200,
                'message' => 'Recibo de pago anulado exitosamente',
                'payment_receipt_id' => $receiptLock->id,
                'numero_recibo' => $receiptLock->numero_recibo,
                'ventas_recalculadas' => collect($ventasLock)->map(fn ($v) => [
                    'sale_id' => $v->id,
                    'saldo_pendiente' => (float) $v->fresh()->saldo_pendiente,
                ])->values(),
            ]);
        });
    }

    // Módulo Caja — Fase 6. Delega en CashCorrectionService (extraído tras
    // encontrar este mismo patrón replicado en SaleController/
    // CashMovementController) — encuentra el cash_movement del recibo y le
    // pide al servicio que lo revierta en la sesión abierta ACTUAL de quien
    // anula, con el gate de cash.close_others_session si su sesión ya
    // cerró.
    //
    // Un recibo anterior a esta fase no tiene cash_movement que revertir —
    // eso NO bloquea la anulación (el resto de payment_applications/saldo
    // sí se revierte igual), simplemente no hay nada que tocar en caja.
    private function revertirCashMovementDeRecibo(PaymentReceipt $receipt): void
    {
        $original = CashMovement::where('reference_type', 'payment_receipt')
            ->where('reference_id', $receipt->id)
            ->where('type', 'installment_payment')
            ->whereNull('corrected_by')
            ->first();

        if (!$original) {
            return;
        }

        $this->cashCorrection->revertirMovimiento($original, auth('api')->user());
    }

    // ── POST /sales/{sale}/refund ─────────────────────────────────────────
    // §3.12 — devolución de pagos por venta anulada CON mercadería devuelta
    // y retención parcial. Distinto de anular() arriba (§3.6): ahí el pago
    // nunca debió existir así; acá el pago fue correcto en su momento, lo
    // que cambia es que la venta ya no se sostiene (nota de crédito) y
    // corresponde liquidar/devolver.
    //
    // Precondición verificada en código (no asumida): Sale::tieneNotaCreditoTotalAceptada()
    // (Sale.php, ya usada como guardián real por NotaElectronicaController)
    // — exige NC total (tipo_doc='07', tipo_afectacion='total', status='aceptado').
    // Si la venta no tiene eso, 422: este endpoint no es para §3.9 (venta
    // anulada SIN pagos, fuera de alcance de este módulo).
    //
    // saldo_pendiente SÍ se toca acá (corrección sobre la instrucción original):
    // se fuerza a 0 dentro de esta misma transacción. Encontrado en código
    // (no asumido): CreditPaymentAllocator::allocate() filtra ventas
    // elegibles para el FIFO con `saldo_pendiente > 0` sin ninguna
    // conciencia de NC — dejar saldo_pendiente intacto habría hecho que
    // esta venta ya liquidada siguiera apareciendo como cobrable en el
    // próximo preview de pagos del cliente. El rastro de auditoría completo
    // sigue vivo en payment_applications (estado=anulada + refund_id) y en
    // la fila de payment_refunds — no se pierde nada al ponerlo en 0.
    public function refund(Request $request, Sale $sale)
    {
        $request->validate([
            'monto_retenido' => 'required|numeric|min:0',
            'motivo_retencion' => 'required|string|min:5',
            'medio_devolucion' => 'required|string',
            'nro_operacion_devolucion' => 'nullable|string',
            'fecha_devolucion' => 'required|date',
        ]);

        if (!$sale->tieneNotaCreditoTotalAceptada()) {
            abort(422, "La venta #{$sale->id} no tiene una nota de crédito TOTAL aceptada — " .
                "este endpoint es solo para liquidar devoluciones de ventas ya anuladas por NC " .
                "(§3.12). Si la venta se anula sin pagos aplicados, ver §3.9 (fuera de este módulo).");
        }

        return DB::transaction(function () use ($request, $sale) {
            $ventaLock = Sale::whereKey($sale->id)->lockForUpdate()->first();

            $aplicacionesActivas = PaymentApplication::where('sale_id', $ventaLock->id)
                ->where('estado', 'activo')
                ->lockForUpdate()
                ->get();

            if ($aplicacionesActivas->isEmpty()) {
                abort(422, "La venta #{$ventaLock->id} no tiene payment_applications activas — " .
                    "no hay nada que devolver. Si nunca tuvo pagos, este caso es §3.9, fuera de " .
                    "este módulo.");
            }

            // Recalculado server-side, no confiado del payload — mismo
            // criterio que preview()/store() de pagos (Fase 4).
            $montoPagadoTotalCentavos = (int) $aplicacionesActivas->sum(
                fn ($a) => (int) round((float) $a->monto_aplicado * 100)
            );

            $montoRetenidoCentavos = (int) round((float) $request->monto_retenido * 100);

            if ($montoRetenidoCentavos > $montoPagadoTotalCentavos) {
                abort(422, sprintf(
                    'El monto retenido (%s) no puede superar el monto pagado total de la venta (%s).',
                    number_format($montoRetenidoCentavos / 100, 2),
                    number_format($montoPagadoTotalCentavos / 100, 2)
                ));
            }

            $montoDevueltoCentavos = $montoPagadoTotalCentavos - $montoRetenidoCentavos;

            // Estado inicial 'completado' (ver explicación en la respuesta
            // al usuario, no asumido en silencio): este endpoint captura
            // medio_devolucion/fecha_devolucion/nro_operacion_devolucion
            // como hechos YA ocurridos, no como una intención pendiente de
            // ejecutar — "liquidar" es el verbo que usa el propio plan para
            // este endpoint. No hay, en esta fase, ningún endpoint que
            // transicione pendiente -> completado, así que dejarlo en
            // 'pendiente' lo dejaría permanentemente varado ahí.
            $refund = PaymentRefund::create([
                'sale_id' => $ventaLock->id,
                'monto_pagado_total' => round($montoPagadoTotalCentavos / 100, 2),
                'monto_retenido' => round($montoRetenidoCentavos / 100, 2),
                'motivo_retencion' => $request->motivo_retencion,
                'monto_devuelto' => round($montoDevueltoCentavos / 100, 2),
                'medio_devolucion' => $request->medio_devolucion,
                'nro_operacion_devolucion' => $request->nro_operacion_devolucion,
                'fecha_devolucion' => $request->fecha_devolucion,
                'autorizado_por' => auth('api')->id(),
                'estado' => 'completado',
            ]);

            foreach ($aplicacionesActivas as $aplicacion) {
                $aplicacion->estado = 'anulada';
                $aplicacion->refund_id = $refund->id;
                $aplicacion->save();
            }

            $ventaLock->saldo_pendiente = 0;
            $ventaLock->save();
            $this->actualizarStatePayment($ventaLock);

            return response()->json([
                'code' => 200,
                'message' => 'Devolución liquidada exitosamente',
                'sale_id' => $ventaLock->id,
                'payment_refund_id' => $refund->id,
                'monto_pagado_total' => (float) $refund->monto_pagado_total,
                'monto_retenido' => (float) $refund->monto_retenido,
                'monto_devuelto' => (float) $refund->monto_devuelto,
                'estado' => $refund->estado,
                'applications_anuladas' => $aplicacionesActivas->pluck('id')->values(),
            ]);
        });
    }

    // ── POST /sales/{sale}/replace ─────────────────────────────────────────
    // §3.13 — {sale} es la venta VIEJA (la que se anula). Reemplazo de
    // comprobante por error en montos/producto, con traspaso de pagos ya
    // existentes — a diferencia de refund() (§3.12), el cliente NO devuelve
    // nada, la operación comercial sigue siendo válida, solo se corrigió el
    // documento.
    //
    // Precondición (mismo criterio que refund()): la venta VIEJA necesita
    // Sale::tieneNotaCreditoTotalAceptada().
    //
    // Vínculo previo entre ventas — verificado en código, no asumido: hoy
    // NO existe ningún flujo que cree/vincule una venta nueva a una vieja
    // (grep de replaces_sale_id solo encuentra el modelo/migración/casts).
    // El cajero crea la venta nueva por el flujo normal de SaleController
    // (sin tocar ese controlador, fuera de alcance) y DESPUÉS llama a este
    // endpoint pasándole el id de esa venta ya creada — este endpoint arma
    // TODO el vínculo (replaces_sale_id + traspaso de pagos) en un solo
    // paso, no hay un paso previo de "vincular" separado.
    //
    // Hallazgo adicional (confirmado, no asumido): SaleController::store()
    // nunca inicializa saldo_pendiente (queda en el default 0 de la
    // migración) — mismo gap que condicion_pago/credit_type en Fase 3. Sin
    // corregirlo acá, la venta nueva llegaría con saldo_pendiente=0 y el
    // 100% del traspaso caería como excedente sin aplicarse a la deuda
    // real. Se inicializa a total() dentro de esta misma transacción si
    // llega en 0 — decisión confirmada con el usuario, alcance contenido a
    // la venta nueva.
    public function replace(Request $request, Sale $sale)
    {
        $request->validate([
            'sale_id_nueva' => 'required|integer|exists:sales,id',
        ]);

        $ventaNuevaId = (int) $request->sale_id_nueva;

        if ($ventaNuevaId === $sale->id) {
            abort(422, 'La venta nueva no puede ser la misma venta que se está reemplazando.');
        }

        $ventaVieja = $sale;

        if (!$ventaVieja->tieneNotaCreditoTotalAceptada()) {
            abort(422, "La venta #{$ventaVieja->id} no tiene una nota de crédito TOTAL aceptada — " .
                "el reemplazo de comprobante (§3.13) requiere que la venta vieja ya esté anulada por NC.");
        }

        return DB::transaction(function () use ($ventaVieja, $ventaNuevaId) {
            $ventaViejaLock = Sale::whereKey($ventaVieja->id)->lockForUpdate()->first();
            $ventaNuevaLock = Sale::whereKey($ventaNuevaId)->lockForUpdate()->first();

            if ((int) $ventaNuevaLock->client_id !== (int) $ventaViejaLock->client_id) {
                abort(422, "La venta nueva #{$ventaNuevaLock->id} pertenece a un cliente distinto " .
                    "de la venta vieja #{$ventaViejaLock->id} — no se puede traspasar el pago.");
            }

            if ($ventaNuevaLock->replaces_sale_id) {
                abort(422, "La venta #{$ventaNuevaLock->id} ya reemplaza a otra venta " .
                    "(#{$ventaNuevaLock->replaces_sale_id}).");
            }

            $aplicacionesViejas = PaymentApplication::where('sale_id', $ventaViejaLock->id)
                ->where('estado', 'activo')
                ->orderBy('id')
                ->get();

            if ($aplicacionesViejas->isEmpty()) {
                abort(422, "La venta #{$ventaViejaLock->id} no tiene payment_applications activas " .
                    "para traspasar.");
            }

            // Hallazgo confirmado (ver comentario arriba): inicializar acá
            // si nadie lo hizo antes.
            if ((float) $ventaNuevaLock->saldo_pendiente <= 0 && (float) $ventaNuevaLock->total > 0) {
                $ventaNuevaLock->saldo_pendiente = $ventaNuevaLock->total;
                $ventaNuevaLock->save();
            }

            $ventaNuevaLock->replaces_sale_id = $ventaViejaLock->id;
            $ventaNuevaLock->save();

            $clienteNuevo = Client::findOrFail($ventaNuevaLock->client_id);

            $siguienteOrden = 1 + (int) (
                PaymentApplication::where('sale_id', $ventaNuevaLock->id)->max('orden_aplicacion') ?? 0
            );
            $excedenteCentavos = 0;

            // Se procesa cada payment_application VIEJA individualmente (no
            // el total agregado) para que cada fila nueva preserve el
            // payment_receipt_id original y origen_application_id exacto —
            // pero la SELECCIÓN de a qué cuota/venta se aplica reusa
            // CreditPaymentAllocator::allocate() (Fase 4) sin reescribir
            // nada del FIFO, llamándolo una vez por cada monto a traspasar
            // contra el saldo YA actualizado de la venta nueva en la
            // iteración anterior (misma transacción, misma conexión).
            foreach ($aplicacionesViejas as $aplicacionVieja) {
                $montoATraspasarCentavos = (int) round((float) $aplicacionVieja->monto_aplicado * 100);

                if ((float) $ventaNuevaLock->saldo_pendiente > 0) {
                    // incluirMora=false (Fase 6): un traspaso de pagos ya
                    // existentes (§3.13) no es un evento de cobro nuevo —
                    // no debe generar mora fresca sobre la venta nueva solo
                    // por relinkear dinero que ya estaba pagado.
                    $resultado = $this->allocator->allocate(
                        $clienteNuevo,
                        $montoATraspasarCentavos / 100,
                        $ventaNuevaLock->id,
                        null,
                        false
                    );

                    foreach ($resultado['aplicaciones'] as $fila) {
                        PaymentApplication::create([
                            'payment_receipt_id' => $aplicacionVieja->payment_receipt_id,
                            'sale_id' => $ventaNuevaLock->id,
                            'installment_id' => $fila['installment_id'],
                            'monto_aplicado' => $fila['monto_aplicado'],
                            // 0 a propósito: la mora ya cobrada de la fila
                            // vieja queda registrada tal cual en ESA fila
                            // (ahora 'trasladada', nunca se borra) — no se
                            // recalcula ni se copia acá, porque cuando un
                            // pago viejo se divide en 2+ filas nuevas
                            // (cuota distinta) no hay un criterio único para
                            // prorratear un monto de mora históricamente
                            // fijo entre ellas sin inventarlo.
                            'monto_mora_cobrado' => 0,
                            'orden_aplicacion' => $siguienteOrden++,
                            'estado' => 'activo',
                            'origen_application_id' => $aplicacionVieja->id,
                        ]);

                        if ($fila['installment_id']) {
                            $cuota = Installment::whereKey($fila['installment_id'])->lockForUpdate()->first();
                            $saldoCuotaCentavos = $this->allocator->saldoCentavosInstallment($cuota->fresh());
                            $cuota->estado = $saldoCuotaCentavos <= 0 ? 'pagada' : 'parcial';
                            $cuota->save();
                        }

                        $montoFilaCentavos = (int) round((float) $fila['monto_aplicado'] * 100);
                        $ventaNuevaLock->saldo_pendiente = round(
                            ((int) round((float) $ventaNuevaLock->saldo_pendiente * 100) - $montoFilaCentavos) / 100,
                            2
                        );
                        $ventaNuevaLock->save();
                    }

                    $excedenteCentavos += (int) round($resultado['monto_no_aplicado'] * 100);
                } else {
                    // La venta nueva ya no tiene saldo — todo este traspaso
                    // queda como excedente (§3.13: "si el comprobante
                    // corregido queda con menor total... el excedente cae
                    // en saldo_a_favor").
                    $excedenteCentavos += $montoATraspasarCentavos;
                }

                $aplicacionVieja->estado = 'trasladada';
                $aplicacionVieja->save();
            }

            // Venta vieja: mismo criterio que refund() (§3.12) — ya tiene
            // NC, no debe seguir apareciendo como cobrable en el FIFO.
            $ventaViejaLock->saldo_pendiente = 0;
            $ventaViejaLock->save();
            $this->actualizarStatePayment($ventaViejaLock);
            $this->actualizarStatePayment($ventaNuevaLock);

            if ($excedenteCentavos > 0) {
                $clienteNuevo->increment('saldo_a_favor', round($excedenteCentavos / 100, 2));
            }

            return response()->json([
                'code' => 200,
                'message' => 'Comprobante reemplazado y pagos traspasados exitosamente',
                'sale_id_vieja' => $ventaViejaLock->id,
                'sale_id_nueva' => $ventaNuevaLock->id,
                'monto_traspasado' => (float) $aplicacionesViejas->sum('monto_aplicado'),
                'excedente_a_saldo_favor' => round($excedenteCentavos / 100, 2),
                'sale_nueva_saldo_pendiente' => (float) $ventaNuevaLock->fresh()->saldo_pendiente,
            ]);
        });
    }

    // ── Correlativo interno de payment_receipts (REC-00001) ──────────────
    // Mismo patrón que FacturacionElectronicaController::reservarCorrelativo():
    // lockForUpdate() + MAX()+1, para que dos cobros simultáneos no generen
    // el mismo número. A diferencia de ese método, esta función NO abre su
    // propia transacción — corre dentro de la que ya abrió store(), para
    // que el lock viva hasta el commit final del recibo completo.
    private function siguienteNumeroRecibo(): string
    {
        $ultimo = PaymentReceipt::whereNotNull('numero_recibo')
            ->orderByRaw("CAST(SUBSTRING(numero_recibo FROM 5) AS INTEGER) DESC")
            ->lockForUpdate()
            ->first();

        $siguiente = $ultimo ? ((int) substr($ultimo->numero_recibo, 4)) + 1 : 1;

        return 'REC-' . str_pad((string) $siguiente, 5, '0', STR_PAD_LEFT);
    }

    // ── Sincroniza sales.state_payment con saldo_pendiente ────────────────
    // Gap real (no un olvido de esta corrección): este módulo siempre
    // mantuvo saldo_pendiente al día, pero nunca tocó state_payment (el
    // campo 1/2/3 que pinta "Pendiente"/"Parcial"/"Pagado" en sale/index.vue)
    // — a diferencia de SalePaymentController (flujo legado de contado), que
    // sí lo actualiza al cobrar/revertir un pago. Consecuencia real: una
    // venta a crédito totalmente pagada vía Cuentas por Cobrar seguía
    // apareciendo "Pendiente" en el listado de ventas. Mismo criterio 1/2/3
    // que SalePaymentController::store(), pero basado en saldo_pendiente
    // (fuente de verdad de ESTE módulo) en vez de debt/paid_out (que este
    // módulo no mantiene al día más allá de la creación de la venta — no se
    // usan acá a propósito). "Parcial" se determina por si queda al menos
    // una payment_application activa, no comparando contra el total bruto:
    // saldo_pendiente arranca ya neto de adelantos/pago inicial (Fase 8), no
    // del total de la venta, así que compararlo contra total daría falsos
    // "parcial" en ventas que nunca recibieron un cobro por este módulo.
    private function actualizarStatePayment(Sale $venta): void
    {
        $venta->refresh();

        if ((float) $venta->saldo_pendiente <= 0) {
            $state = 3; // pagado
        } elseif ($venta->paymentApplications()->where('estado', 'activo')->exists()) {
            $state = 2; // parcial — al menos un cobro activo, saldo > 0
        } else {
            $state = 1; // pendiente — sin cobros activos de este módulo
        }

        $venta->update(['state_payment' => $state]);
    }
}
