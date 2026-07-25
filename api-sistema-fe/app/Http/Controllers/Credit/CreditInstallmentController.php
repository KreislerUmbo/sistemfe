<?php

namespace App\Http\Controllers\Credit;

use App\Http\Controllers\Controller;
use App\Models\Credit\Installment;
use App\Models\Credit\PaymentApplication;
use App\Models\Sale\Sale;
use App\Services\CreditPaymentAllocator;
use App\Services\InstallmentScheduleCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §3.1, §4.
// Cronograma de cuotas fijas: generación (preview, no persiste),
// confirmación (persiste) y edición puntual de una cuota. La anulación de
// cuotas (§3.5) es Fase 5, no vive acá.
//
// Precondición que este controlador NO resuelve: asume que la venta ya
// llegó con condicion_pago='credito' y credit_type='cuotas_fijas' seteados
// por algún flujo externo. Verificado (no asumido) que hoy no existe ese
// flujo en SaleController ni en el frontend — sigue pendiente, fuera de
// alcance de esta tarea (ver §9 del plan, decisión de sync con
// type_payment todavía sin cerrar).
class CreditInstallmentController extends Controller
{
    protected $schedule_calculator;
    protected $allocator;

    public function __construct(InstallmentScheduleCalculator $schedule_calculator, CreditPaymentAllocator $allocator)
    {
        $this->schedule_calculator = $schedule_calculator;
        $this->allocator = $allocator;
    }

    // ── Formato de respuesta compartido por store()/update() ────────────
    // fecha_vencimiento está cast a 'date' en el modelo (igual que
    // TaxConfig::fecha_inicio_vigencia/SunatConfig::certificado_fecha_vencimiento
    // en el resto del proyecto) — el cast en sí ya es consistente. Lo que
    // rompía el formato era devolver el modelo crudo en response()->json():
    // Laravel serializa cualquier cast de fecha vía toJSON() (con hora/TZ),
    // sin importar si el cast es 'date' o 'datetime'. La forma en que el
    // proyecto ya resuelve esto es formateando explícito en el punto de
    // salida (ver SaleResource::toArray(), 'sunat_sent_at'/'date_payment'
    // con ->format('Y-m-d')) — mismo criterio acá, no un cast nuevo.
    private function formatearInstallment(Installment $installment): array
    {
        return [
            'id' => $installment->id,
            'sale_id' => $installment->sale_id,
            'numero_cuota' => $installment->numero_cuota,
            'monto_programado' => (float) $installment->monto_programado,
            'fecha_vencimiento' => $installment->fecha_vencimiento?->format('Y-m-d'),
            'estado' => $installment->estado,
        ];
    }

    // ── Validación compartida por los 3 endpoints ───────────────────────
    private function validarVentaCuotasFijas(Sale $sale): void
    {
        if ($sale->condicion_pago !== 'credito' || $sale->credit_type !== 'cuotas_fijas') {
            abort(422, "La venta #{$sale->id} no está configurada como crédito con cuotas fijas " .
                "(condicion_pago='{$sale->condicion_pago}', credit_type='{$sale->credit_type}'). " .
                "Este módulo no marca la venta como crédito — debe llegar así ya seteada.");
        }
    }

    // ── POST /sales/{sale}/installments/preview ─────────────────────────
    // Genera el cronograma sugerido. No persiste nada en installments.
    public function preview(Request $request, Sale $sale)
    {
        $this->validarVentaCuotasFijas($sale);

        $request->validate([
            'num_cuotas' => 'required|integer|min:1|max:60',
            'periodicidad' => 'required|string|in:mensual,quincenal,semanal,personalizada',
        ]);

        if ((float) $sale->saldo_pendiente <= 0) {
            abort(422, "La venta #{$sale->id} no tiene saldo_pendiente > 0 — no hay nada que financiar en cuotas.");
        }

        $cronograma = $this->schedule_calculator->generar(
            (float) $sale->saldo_pendiente,
            (int) $request->num_cuotas,
            $request->periodicidad,
            $sale->date ? Carbon::parse($sale->date) : now()
        );

        return response()->json([
            'sale_id' => $sale->id,
            'saldo_pendiente' => (float) $sale->saldo_pendiente,
            'cronograma' => $cronograma,
        ]);
    }

    // ── POST /installments/schedule-preview ──────────────────────────────
    // Módulo Amortizaciones — Fase 8. Mismo cálculo que preview() de
    // arriba, pero SIN requerir una Sale ya persistida — InstallmentScheduleCalculator::generar()
    // ya no depende de un Sale (toma monto/num_cuotas/periodicidad/fecha
    // directo), así que no hay necesidad real de reimplementar el
    // redondeo a centavos en el frontend solo porque la venta todavía no
    // tiene id (formulario de creación, register.vue). No persiste nada.
    public function previewSchedule(Request $request)
    {
        $request->validate([
            'monto_total' => 'required|numeric|min:0.01',
            'num_cuotas' => 'required|integer|min:1|max:60',
            'periodicidad' => 'required|string|in:mensual,quincenal,semanal,personalizada',
            'fecha_anchor' => 'nullable|date',
        ]);

        $cronograma = $this->schedule_calculator->generar(
            (float) $request->monto_total,
            (int) $request->num_cuotas,
            $request->periodicidad,
            $request->filled('fecha_anchor') ? Carbon::parse($request->fecha_anchor) : now()
        );

        return response()->json([
            'monto_total' => (float) $request->monto_total,
            'cronograma' => $cronograma,
        ]);
    }

    // ── POST /sales/{sale}/installments ──────────────────────────────────
    // Persiste el cronograma ya confirmado/editado por el cajero. Bloquea
    // si la venta ya tiene un cronograma activo.
    //
    // Validación de suma (decisión de esta fase, no asumida en silencio):
    // se exige que la suma de monto_programado calce EXACTO con
    // sale.saldo_pendiente, no con sale.total. Razón: saldo_pendiente es el
    // campo cacheado que todo el módulo trata como fuente de verdad de "lo
    // que falta cobrar" (§2.1) — para una venta a crédito recién marcada,
    // sin pagos aplicados todavía (Fase 4 no existe aún), saldo_pendiente
    // debería equivaler a total, pero el cronograma se valida contra
    // saldo_pendiente para no romperse el día que exista un flujo que deje
    // la venta con saldo_pendiente < total antes de armar cuotas (ej. un
    // adelanto ya aplicado). La comparación es en centavos enteros, no
    // floats, mismo criterio que InstallmentScheduleCalculator.
    public function store(Request $request, Sale $sale)
    {
        $this->validarVentaCuotasFijas($sale);

        $request->validate([
            'cuotas' => 'required|array|min:1',
            'cuotas.*.numero_cuota' => 'required|integer|min:1',
            'cuotas.*.monto_programado' => 'required|numeric|min:0.01',
            'cuotas.*.fecha_vencimiento' => 'required|date',
        ]);

        return DB::transaction(function () use ($request, $sale) {
            // Relee bajo lock: evita que dos requests concurrentes pasen
            // ambos el chequeo de "no tiene cronograma todavía" (mismo
            // patrón que el resto del proyecto, ver reservarCorrelativo()).
            $ventaLock = Sale::whereKey($sale->id)->lockForUpdate()->first();

            $yaTieneCronograma = Installment::where('sale_id', $ventaLock->id)
                ->where('estado', '!=', 'anulada')
                ->exists();

            if ($yaTieneCronograma) {
                abort(422, "La venta #{$ventaLock->id} ya tiene un cronograma activo. " .
                    "Para reemplazarlo hay que anular las cuotas existentes primero (Fase 5, §3.5).");
            }

            $cuotas = collect($request->cuotas);

            $numerosUnicos = $cuotas->pluck('numero_cuota')->unique();
            if ($numerosUnicos->count() !== $cuotas->count()) {
                abort(422, "numero_cuota no puede repetirse dentro del mismo cronograma.");
            }

            $sumaCentavos = $cuotas->sum(fn ($c) => (int) round(((float) $c['monto_programado']) * 100));
            $saldoCentavos = (int) round(((float) $ventaLock->saldo_pendiente) * 100);

            if ($sumaCentavos !== $saldoCentavos) {
                abort(422, sprintf(
                    "La suma de monto_programado (%s) no calza con el saldo_pendiente de la venta (%s).",
                    number_format($sumaCentavos / 100, 2),
                    number_format($saldoCentavos / 100, 2)
                ));
            }

            $creadas = collect();
            foreach ($cuotas->sortBy('numero_cuota') as $c) {
                $creadas->push(Installment::create([
                    'sale_id' => $ventaLock->id,
                    'numero_cuota' => $c['numero_cuota'],
                    'monto_programado' => $c['monto_programado'],
                    'fecha_vencimiento' => $c['fecha_vencimiento'],
                    'estado' => 'pendiente',
                ]));
            }

            return response()->json([
                'code' => 200,
                'message' => 'Cronograma de cuotas registrado exitosamente',
                'sale_id' => $ventaLock->id,
                'installments' => $creadas->map(fn ($i) => $this->formatearInstallment($i))->values(),
            ]);
        });
    }

    // ── PATCH /installments/{installment} ───────────────────────────────
    // Edita fecha_vencimiento y/o monto_programado de UNA cuota puntual
    // (renegociación simple, sin anular). Bloquea si la cuota no está
    // 'pendiente' — la validación queda lista aunque el flujo de pagos
    // (Fase 4) todavía no exista, para cuando sí pueda dejar una cuota en
    // 'pagada'/'parcial'/'vencida'.
    //
    // OJO — no revalida que la suma de TODAS las cuotas de la venta siga
    // cuadrando con saldo_pendiente después de este PATCH: es una edición
    // de una sola fila (§3.1), no hay endpoint batch en esta fase para
    // mover monto de una cuota a otra de forma atómica. Si el cajero
    // necesita "quitarle a la cuota 2 y ponerle a la 3", son 2 PATCH
    // separados y el total puede quedar transitoriamente descuadrado entre
    // uno y otro — decisión explícita, no un descuido.
    public function update(Request $request, Installment $installment)
    {
        $request->validate([
            'fecha_vencimiento' => 'sometimes|required|date',
            'monto_programado' => 'sometimes|required|numeric|min:0.01',
        ]);

        if (!$request->hasAny(['fecha_vencimiento', 'monto_programado'])) {
            abort(422, "Debe enviar al menos fecha_vencimiento o monto_programado para editar.");
        }

        return DB::transaction(function () use ($request, $installment) {
            $cuotaLock = Installment::whereKey($installment->id)->lockForUpdate()->first();

            if ($cuotaLock->estado !== 'pendiente') {
                abort(422, "No se puede editar la cuota #{$cuotaLock->numero_cuota}: está en estado " .
                    "'{$cuotaLock->estado}'. Solo se puede editar una cuota 'pendiente' (sin pagos aplicados).");
            }

            $cuotaLock->fill($request->only(['fecha_vencimiento', 'monto_programado']));
            $cuotaLock->save();

            return response()->json([
                'code' => 200,
                'message' => 'Cuota actualizada',
                'installment' => $this->formatearInstallment($cuotaLock),
            ]);
        });
    }

    // ── POST /installments/{installment}/anular ──────────────────────────
    // §3.5 — cuota SIN pagos aplicados. Bloquea si ya tiene
    // payment_applications con estado='activo' (hay que anular el pago
    // primero, ver PaymentReceipt anular en CreditPaymentController — §3.6,
    // edge case ya documentado en el plan).
    //
    // Shape del payload (decisión de esta sub-fase, pedida explícita, no
    // asumida en silencio):
    //   { "accion": "redistribuir" | "condonar", "motivo_anulacion": "..." }
    // motivo_anulacion es SIEMPRE obligatorio (se guarda tal cual en
    // installments.motivo_anulacion sin importar la acción) — cuando
    // accion='condonar' ese mismo texto ES la justificación tipo "descuento
    // comercial" que exige el plan; no agrego un campo separado
    // motivo_condonacion, sería una segunda fuente de "por qué" sobre la
    // misma fila.
    //
    // redistribuir: PROPORCIONAL entre las cuotas restantes de la misma
    // venta (pendiente/parcial/vencida, sin la anulada) — no "solo en la
    // última" (el plan ofrecía ambas). Proporcional al SALDO PENDIENTE real
    // de cada cuota restante (vía CreditPaymentAllocator::saldoCentavosInstallment,
    // no a su monto_programado original) para no darle una porción más
    // grande a una cuota que ya viene 'parcial'. Centavos enteros, la
    // última cuota (por fecha_vencimiento) absorbe el resto exacto — mismo
    // criterio que InstallmentScheduleCalculator (Fase 3). sale.saldo_pendiente
    // NO cambia: el dinero se reempaqueta entre cuotas, sigue siendo la
    // misma deuda total.
    //
    // condonar: reduce sale.saldo_pendiente en el monto liberado — acá sí
    // es deuda que deja de existir. Usa el mismo permiso
    // anular-cuota-credito que redistribuir: el plan solo dice "considerar"
    // un rol elevado para condonar, no define un permiso Spatie separado
    // (§7 cierra exactamente 2 permisos) — no invento un tercero.
    public function anular(Request $request, Installment $installment)
    {
        $request->validate([
            'accion' => 'required|string|in:redistribuir,condonar',
            'motivo_anulacion' => 'required|string|min:5',
        ]);

        return DB::transaction(function () use ($request, $installment) {
            $cuotaLock = Installment::whereKey($installment->id)->lockForUpdate()->first();

            if ($cuotaLock->estado === 'anulada') {
                abort(422, "La cuota #{$cuotaLock->numero_cuota} ya está anulada.");
            }

            $tienePagoActivo = PaymentApplication::where('installment_id', $cuotaLock->id)
                ->where('estado', 'activo')
                ->exists();

            if ($tienePagoActivo) {
                abort(422, "La cuota #{$cuotaLock->numero_cuota} tiene pagos activos aplicados. " .
                    "Anula primero el pago (POST payment-receipts/{receipt}/anular) antes de " .
                    "anular la cuota.");
            }

            $ventaLock = Sale::whereKey($cuotaLock->sale_id)->lockForUpdate()->first();
            $freedCentavos = (int) round((float) $cuotaLock->monto_programado * 100);

            if ($request->accion === 'condonar') {
                $ventaLock->saldo_pendiente = round(
                    ((int) round((float) $ventaLock->saldo_pendiente * 100) - $freedCentavos) / 100,
                    2
                );
                $ventaLock->save();
            } else {
                $cuotasRestantes = Installment::where('sale_id', $ventaLock->id)
                    ->where('id', '!=', $cuotaLock->id)
                    ->whereIn('estado', ['pendiente', 'parcial', 'vencida'])
                    ->orderBy('fecha_vencimiento')
                    ->get();

                if ($cuotasRestantes->isEmpty()) {
                    abort(422, "No hay otras cuotas pendientes en la venta #{$ventaLock->id} para " .
                        "redistribuir el monto liberado — usa 'condonar' en su lugar.");
                }

                $saldosCentavos = $cuotasRestantes->mapWithKeys(
                    fn ($c) => [$c->id => $this->allocator->saldoCentavosInstallment($c)]
                );
                $pesoTotalCentavos = $saldosCentavos->sum();
                $ultimaCuotaId = $cuotasRestantes->last()->id;

                $asignadoCentavos = 0;
                foreach ($cuotasRestantes as $c) {
                    if ($c->id === $ultimaCuotaId) {
                        $incrementoCentavos = $freedCentavos - $asignadoCentavos; // resto exacto
                    } elseif ($pesoTotalCentavos > 0) {
                        $incrementoCentavos = (int) floor($freedCentavos * ($saldosCentavos[$c->id] / $pesoTotalCentavos));
                    } else {
                        // Edge case raro: todas las cuotas restantes en saldo 0 — reparte parejo.
                        $incrementoCentavos = (int) floor($freedCentavos / $cuotasRestantes->count());
                    }

                    $c->monto_programado = round(
                        ((int) round((float) $c->monto_programado * 100) + $incrementoCentavos) / 100,
                        2
                    );
                    $c->save();

                    $asignadoCentavos += $incrementoCentavos;
                }
                // sale.saldo_pendiente NO cambia — el total sigue siendo el mismo.
            }

            date_default_timezone_set('America/Lima');
            $cuotaLock->estado = 'anulada';
            $cuotaLock->motivo_anulacion = $request->motivo_anulacion;
            $cuotaLock->anulado_por = auth('api')->id();
            $cuotaLock->anulado_en = now();
            $cuotaLock->save();

            return response()->json([
                'code' => 200,
                'message' => 'Cuota anulada exitosamente',
                'accion' => $request->accion,
                'installment' => $this->formatearInstallment($cuotaLock),
                'sale_saldo_pendiente' => (float) $ventaLock->fresh()->saldo_pendiente,
            ]);
        });
    }
}
