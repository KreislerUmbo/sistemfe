<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashRegister;
use App\Models\Cash\CashSession;
use App\Models\Cash\CashSessionDenomination;
use App\Models\Cash\PaymentMethod;
use App\Models\Company;
use App\Services\CashVisibilityResolver;
use App\Services\ExpectedCashCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Módulo Caja — Fase 2 (plan-modulo-caja.md §6, §9). Apertura/cierre de
// sesión únicamente. Sin integración con ventas (Fase 3) ni movimientos
// manuales (Fase 4) — el único cash_movement que este controller genera es
// 'opening_fund'. Fase 5 (reportes) agrega index/show/dashboard/pdf/pdfRange
// sobre el mismo recurso (mismo criterio de un-controller-por-recurso que
// CashMovementController).
class CashSessionController extends Controller
{
    public function __construct(
        private ExpectedCashCalculator $expectedCashCalculator,
        private CashVisibilityResolver $visibility,
    ) {
    }

    // ── Estado actual ──────────────────────────────────────────────────
    // No existe hoy un mecanismo de "caja asignada al usuario" — branches/
    // cash_registers todavía no tienen su propio CRUD (nota del plan §4).
    // Este endpoint resuelve la sesión abierta DEL USUARIO AUTENTICADO
    // (opened_by = auth user); si no tiene ninguna, devuelve las cajas
    // activas SIN sesión abierta para que el frontend arme el selector
    // (fija si hay una sola, selector si hay más de una — decisión de UI
    // en Paso 2, no acá).
    public function status()
    {
        $user = auth('api')->user();

        // difference_tolerance viaja siempre (haya o no sesión abierta) —
        // el frontend la necesita para replicar la validación de motivo
        // obligatorio del lado cliente (plan: "validación también en
        // frontend, no solo backend"), aunque el backend siga siendo la
        // fuente de verdad real en close().
        $differenceTolerance = (float) (Company::first()?->difference_tolerance ?? 2.00);

        $session = CashSession::where('opened_by', $user->id)
            ->where('status', 'open')
            ->first();

        if (!$session) {
            $availableRegisters = CashRegister::where('is_active', true)
                ->whereDoesntHave('cashSessions', function ($query) {
                    $query->where('status', 'open');
                })
                ->with('branch')
                ->get()
                ->map(function ($register) {
                    $register->blind_close_resolved = $this->resolvedBlindClose($register);

                    return $register;
                });

            return response()->json([
                'has_open_session' => false,
                'session' => null,
                'available_registers' => $availableRegisters,
                'difference_tolerance' => $differenceTolerance,
            ]);
        }

        return response()->json([
            'has_open_session' => true,
            'session' => $this->serializeSession($session),
            'difference_tolerance' => $differenceTolerance,
        ]);
    }

    // ── Abrir sesión ─────────────────────────────────────────────────
    public function open(Request $request)
    {
        $request->validate([
            'cash_register_id' => 'required|integer|exists:cash_registers,id',
            'opening_amount' => 'required|numeric|min:0',
        ]);

        $user = auth('api')->user();

        $session = DB::transaction(function () use ($request, $user) {
            // lockForUpdate() sobre la caja serializa el check-then-insert
            // frente a otro request concurrente sobre LA MISMA caja — mismo
            // patrón que reservarCorrelativo(). El índice único parcial de
            // Postgres (cash_sessions_one_open_per_register) es la última
            // línea de defensa, no la única.
            $register = CashRegister::where('id', $request->cash_register_id)
                ->lockForUpdate()
                ->first();

            if (!$register->is_active) {
                throw new HttpException(422, 'Esta caja está inactiva.');
            }

            $yaAbierta = CashSession::where('cash_register_id', $register->id)
                ->where('status', 'open')
                ->exists();

            if ($yaAbierta) {
                throw new HttpException(422, 'Esta caja ya tiene una sesión abierta.');
            }

            $openingAmount = round((float) $request->opening_amount, 2);
            $adjusted = abs($openingAmount - (float) $register->default_opening_amount) > 0.005;

            try {
                $nuevaSesion = CashSession::create([
                    'cash_register_id' => $register->id,
                    'opened_by' => $user->id,
                    'opening_amount' => $openingAmount,
                    'opening_amount_adjusted' => $adjusted,
                    'opened_at' => now(),
                    'status' => 'open',
                ]);
            } catch (UniqueConstraintViolationException) {
                // Backstop real ante una carrera que el chequeo de arriba no
                // alcanzó a atajar (no debería pasar gracias al lockForUpdate,
                // pero el índice es quien de verdad lo garantiza).
                throw new HttpException(422, 'Esta caja ya tiene una sesión abierta.');
            }

            // Fondo de apertura — siempre efectivo físico.
            $efectivo = PaymentMethod::where('code', 'EFECTIVO')->first();

            if (!$efectivo) {
                throw new HttpException(500, 'No se encontró el método de pago EFECTIVO — revisa PaymentMethodSeeder.');
            }

            CashMovement::create([
                'cash_session_id' => $nuevaSesion->id,
                'type' => 'opening_fund',
                'payment_method_id' => $efectivo->id,
                'direction' => 'in',
                'amount' => $openingAmount,
                'status' => 'confirmed',
                'created_by' => $user->id,
            ]);

            return $nuevaSesion;
        });

        return response()->json([
            'code' => 200,
            'message' => 'Caja abierta correctamente',
            'session' => $this->serializeSession($session->fresh()),
        ]);
    }

    // ── Cerrar sesión ────────────────────────────────────────────────
    // cash_session_id es OPCIONAL: si se omite, cierra la sesión abierta del
    // propio usuario (flujo normal de pantalla). Si se manda y coincide con
    // la sesión propia, se trata exactamente igual (no activa la lógica de
    // "cierre por terceros"). Si se manda y pertenece a otro usuario, exige
    // el permiso cash.close_others_session — 403 si no lo tiene (problema de
    // permiso, no de validación de datos).
    public function close(Request $request)
    {
        $request->validate([
            'cash_session_id' => 'nullable|integer|exists:cash_sessions,id',
            'counted_cash' => 'required|numeric|min:0',
            'difference_reason' => 'nullable|string',
            'closing_notes' => 'nullable|string',
            'denominations' => 'nullable|array',
            'denominations.*.denomination' => 'required_with:denominations|numeric|min:0',
            'denominations.*.quantity' => 'required_with:denominations|integer|min:0',
        ]);

        $user = auth('api')->user();

        $session = DB::transaction(function () use ($request, $user) {
            $query = CashSession::where('status', 'open')->lockForUpdate();

            $sesion = $request->filled('cash_session_id')
                ? $query->where('id', $request->cash_session_id)->first()
                : $query->where('opened_by', $user->id)->first();

            if (!$sesion) {
                throw new HttpException(422, 'No hay una sesión abierta para cerrar.');
            }

            $esPropia = (int) $sesion->opened_by === (int) $user->id;

            if (!$esPropia && !$user->can('cash.close_others_session')) {
                throw new HttpException(403, 'No tienes permiso para cerrar la sesión de otro cajero.');
            }

            $company = Company::first();

            $expectedCash = $this->expectedCashCalculator->compute($sesion);

            if ($expectedCash < 0) {
                throw new HttpException(422, 'El efectivo esperado no puede ser negativo — revisa los movimientos de la sesión.');
            }

            $countedCash = round((float) $request->counted_cash, 2);
            $difference = round($countedCash - $expectedCash, 2);
            $tolerance = (float) ($company?->difference_tolerance ?? 2.00);

            if (abs($difference) > $tolerance && !$request->filled('difference_reason')) {
                throw new HttpException(
                    422,
                    'La diferencia (S/ ' . number_format($difference, 2) . ') supera la tolerancia ' .
                    '(S/ ' . number_format($tolerance, 2) . ') — se requiere una justificación.'
                );
            }

            $sesion->update([
                'status' => 'closed',
                'closed_by' => $user->id,
                'closed_at' => now(),
                'expected_cash' => round($expectedCash, 2),
                'counted_cash' => $countedCash,
                'difference' => $difference,
                'difference_reason' => $request->difference_reason,
                'closing_notes' => $request->closing_notes,
            ]);

            foreach ($request->input('denominations', []) as $den) {
                CashSessionDenomination::create([
                    'cash_session_id' => $sesion->id,
                    'denomination' => $den['denomination'],
                    'quantity' => $den['quantity'],
                    'subtotal' => round($den['denomination'] * $den['quantity'], 2),
                ]);
            }

            return $sesion;
        });

        return response()->json([
            'code' => 200,
            'message' => 'Caja cerrada correctamente',
            'session' => $this->serializeSession($session->fresh()),
        ]);
    }

    // ── Historial con filtros (Fase 5, Paso 1) ──────────────────────────
    // Sin cash.view_all, CashVisibilityResolver ignora opened_by pedido y
    // fuerza el propio user_id (no es 403 — el filtro ajeno simplemente no
    // se respeta, ver Paso 8.1 del prompt de Fase 5).
    public function index(Request $request)
    {
        $user = auth('api')->user();
        $openedBy = $this->visibility->resolverOpenedBy($user, $request->query('opened_by'));

        $query = CashSession::with(['cashRegister.branch', 'openedByUser', 'closedByUser']);

        if ($openedBy !== null) {
            $query->where('opened_by', $openedBy);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('opened_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('opened_at', '<=', $request->query('date_to'));
        }
        if ($request->filled('branch_id')) {
            $branchId = $request->query('branch_id');
            $query->whereHas('cashRegister', fn ($q) => $q->where('branch_id', $branchId));
        }
        if ($request->filled('cash_register_id')) {
            $query->where('cash_register_id', $request->query('cash_register_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $sessions = $query->orderByDesc('opened_at')->paginate(25);

        return response()->json([
            'total' => $sessions->total(),
            'paginate' => 25,
            'sessions' => $sessions->getCollection()->map(fn ($s) => $this->formatearResumenSesion($s))->values(),
        ]);
    }

    // ── Detalle de solo lectura (Fase 5, Paso 1b) ───────────────────────
    // Reutiliza serializeSession() — ya expone movimientos, totales por
    // método de pago y denominaciones, todo lo que pide el prompt. Acá sí
    // corresponde 403 explícito (recurso puntual ajeno, no un listado).
    public function show(string $id)
    {
        $session = CashSession::findOrFail($id);
        $this->visibility->verificarAccesoSesion(auth('api')->user(), $session);

        return response()->json(['session' => $this->serializeSession($session)]);
    }

    // ── Dashboard admin (Fase 5, Paso 2) ────────────────────────────────
    // Gateado con permission:cash.view_all a nivel de ruta — es binario
    // (tienes el permiso o ni siquiera entras), a propósito NO usa
    // CashVisibilityResolver acá (esa clase resuelve un filtro parcial
    // dentro de un listado que sí es alcanzable por cualquiera; este
    // endpoint es exclusivo de admin desde la puerta de entrada).
    public function dashboard()
    {
        $registers = CashRegister::where('is_active', true)
            ->with(['branch', 'cashSessions' => function ($query) {
                $query->where('status', 'open')->with('openedByUser');
            }])
            ->get();

        $now = now();

        $data = $registers->map(function ($register) use ($now) {
            $session = $register->cashSessions->first(); // el índice único parcial garantiza máximo 1
            $elapsedHours = null;
            $isStale = false;

            if ($session) {
                $elapsedHours = $session->opened_at->diffInHours($now);
                $isStale = $elapsedHours > 24;
            }

            return [
                'cash_register_id' => $register->id,
                'cash_register_name' => $register->name,
                'branch' => $register->branch,
                'has_open_session' => (bool) $session,
                'session_id' => $session?->id,
                'opened_by_user' => $session?->openedByUser,
                'opened_at' => $session?->opened_at,
                'elapsed_hours' => $elapsedHours,
                'is_stale' => $isStale,
            ];
        })->values();

        return response()->json([
            'registers' => $data,
            'summary' => [
                'total_active_registers' => $data->count(),
                'with_open_session' => $data->where('has_open_session', true)->count(),
                'stale' => $data->where('is_stale', true)->count(),
            ],
        ]);
    }

    // ── PDF de cierre individual (Fase 5, Paso 3) ───────────────────────
    // Mismo patrón de URL firmada que PaymentReceiptController::pdfSignedUrl
    // (window.open() no puede llevar el header Authorization). La
    // verificación de visibilidad ocurre acá (dentro de auth:api) — la ruta
    // pública 'cash-sessions.pdf' solo exige firma válida, igual que el
    // resto de PDFs del proyecto.
    public function pdfSignedUrl(string $id)
    {
        $session = CashSession::findOrFail($id);
        $this->visibility->verificarAccesoSesion(auth('api')->user(), $session);

        $url = URL::temporarySignedRoute('cash-sessions.pdf', now()->addMinutes(10), ['id' => $id]);

        return response()->json(['url' => $url]);
    }

    // Soporta sesión cerrada (dato real, "Cierre Z") y sesión abierta
    // (vista previa con expected_cash_live, marcada explícitamente como tal
    // — nunca se etiqueta "Cierre Z" algo que todavía puede cambiar).
    public function pdf(string $id)
    {
        $session = CashSession::find($id);

        if (!$session) {
            return abort(404);
        }

        $sessionData = $this->serializeSession($session);
        $esPreview = $session->status !== 'closed';
        $empresa = Company::first();

        $pdf = Pdf::loadView('pdf.cash_cierre_individual', [
            'sessionData' => $sessionData,
            'empresa' => $empresa,
            'esPreview' => $esPreview,
        ]);
        $pdf->setPaper('a4', 'portrait');

        $nombreArchivo = ($esPreview ? 'vista_previa_caja_' : 'cierre_caja_') . $session->id . '.pdf';

        return $pdf->stream($nombreArchivo);
    }

    // ── PDF de rango consolidado, máximo 1 mes (Fase 5, Paso 4) ─────────
    // opened_by ya viaja RESUELTO (no crudo) dentro de la URL firmada — la
    // ruta pública no tiene contexto de usuario autenticado, así que la
    // regla de visibilidad se aplica acá, antes de firmar, no en pdfRange().
    public function pdfRangeSignedUrl(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'branch_id' => 'nullable|integer',
            'cash_register_id' => 'nullable|integer',
            'opened_by' => 'nullable|integer',
        ]);

        $this->validarRangoMaximo($request->date_from, $request->date_to);

        $openedBy = $this->visibility->resolverOpenedBy(auth('api')->user(), $request->opened_by);

        $params = [
            'date_from' => $request->date_from,
            'date_to' => $request->date_to,
        ];
        if ($request->filled('branch_id')) {
            $params['branch_id'] = $request->branch_id;
        }
        if ($request->filled('cash_register_id')) {
            $params['cash_register_id'] = $request->cash_register_id;
        }
        if ($openedBy !== null) {
            $params['opened_by'] = $openedBy;
        }

        $url = URL::temporarySignedRoute('cash-sessions.pdf-range', now()->addMinutes(10), $params);

        return response()->json(['url' => $url]);
    }

    public function pdfRange(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $this->validarRangoMaximo($request->date_from, $request->date_to);

        $query = CashSession::with(['cashRegister.branch', 'openedByUser', 'closedByUser', 'cashMovements.paymentMethod'])
            ->whereDate('opened_at', '>=', $request->date_from)
            ->whereDate('opened_at', '<=', $request->date_to);

        if ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
            $query->whereHas('cashRegister', fn ($q) => $q->where('branch_id', $branchId));
        }
        if ($request->filled('cash_register_id')) {
            $query->where('cash_register_id', $request->cash_register_id);
        }
        if ($request->filled('opened_by')) {
            $query->where('opened_by', $request->opened_by);
        }

        $sessions = $query->orderBy('opened_at')->get();
        $empresa = Company::first();

        $sessionsConTotales = $sessions->map(fn ($session) => [
            'session' => $session,
            'totals' => $this->calcularTotalesPorMetodo($session->cashMovements),
        ]);

        $totalesGenerales = $this->calcularTotalesPorMetodo(
            $sessions->flatMap(fn ($session) => $session->cashMovements)
        );

        $pdf = Pdf::loadView('pdf.cash_cierre_rango', [
            'sessionsConTotales' => $sessionsConTotales,
            'totalesGenerales' => $totalesGenerales,
            'empresa' => $empresa,
            'dateFrom' => $request->date_from,
            'dateTo' => $request->date_to,
        ]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream('cierre_caja_rango_' . $request->date_from . '_' . $request->date_to . '.pdf');
    }

    // Regla obligatoria del Paso 4: rechazar con 422 explícito antes de
    // generar nada — nunca un PDF gigante silencioso.
    private function validarRangoMaximo(string $desde, string $hasta): void
    {
        $dias = Carbon::parse($desde)->diffInDays(Carbon::parse($hasta));

        if ($dias > 31) {
            throw new HttpException(422, 'El rango máximo para este reporte es de un mes.');
        }
    }

    // Agrupa un conjunto de cash_movements por método de pago y calcula el
    // neto (in - out), filtrando status='confirmed' — mismo criterio que
    // ExpectedCashCalculator (fix Fase 5: antes serializeSession() no
    // filtraba, ver conversación de esta fase). Compartido por
    // serializeSession() (una sesión) y pdfRange() (varias sesiones + el
    // total general del rango) para no repetir el groupBy.
    private function calcularTotalesPorMetodo($movimientos)
    {
        return $movimientos
            ->where('status', 'confirmed')
            ->groupBy('payment_method_id')
            ->map(function ($movs) {
                $paymentMethod = $movs->first()->paymentMethod;
                $neto = $movs->reduce(function ($carry, $movimiento) {
                    return $carry + ($movimiento->direction === 'in' ? (float) $movimiento->amount : -(float) $movimiento->amount);
                }, 0.0);

                return [
                    'payment_method_id' => $paymentMethod->id,
                    'payment_method_code' => $paymentMethod->code,
                    'payment_method_name' => $paymentMethod->name,
                    'total' => round($neto, 2),
                ];
            })
            ->values();
    }

    private function formatearResumenSesion(CashSession $session): array
    {
        return [
            'id' => $session->id,
            'cash_register' => $session->cashRegister,
            'opened_by_user' => $session->openedByUser,
            'closed_by_user' => $session->closedByUser,
            'opening_amount' => $session->opening_amount,
            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,
            'status' => $session->status,
            'expected_cash' => $session->expected_cash,
            'counted_cash' => $session->counted_cash,
            'difference' => $session->difference,
        ];
    }

    // Módulo Caja — Fase 1, comentario de la migración de companies: esta es
    // la "resolución real" que quedaba pendiente. null en cash_registers.blind_close
    // = hereda companies.blind_close_default; un valor propio siempre gana.
    private function resolvedBlindClose(CashRegister $register): bool
    {
        if (!is_null($register->blind_close)) {
            return (bool) $register->blind_close;
        }

        return (bool) (Company::first()?->blind_close_default ?? false);
    }

    private function serializeSession(CashSession $session): array
    {
        $session->load([
            'cashRegister.branch',
            'openedByUser',
            'closedByUser',
            'cashMovements.paymentMethod',
            'cashMovements.concept',
            'cashSessionDenominations',
        ]);

        $session->cashRegister->blind_close_resolved = $this->resolvedBlindClose($session->cashRegister);

        $totales = $this->calcularTotalesPorMetodo($session->cashMovements);

        // Vista previa en vivo — no persiste nada, distinto de
        // $session->expected_cash (columna, solo se llena al cerrar de
        // verdad). Para una sesión ya cerrada, coincide con lo persistido;
        // para una abierta, es el "corte X" / lo que vería el modo no-ciego
        // antes de confirmar el cierre.
        $expectedCashLive = $session->status === 'closed'
            ? (float) $session->expected_cash
            : $this->expectedCashCalculator->compute($session);

        return [
            'id' => $session->id,
            'cash_register' => $session->cashRegister,
            'opened_by_user' => $session->openedByUser,
            'closed_by_user' => $session->closedByUser,
            'opening_amount' => $session->opening_amount,
            'opening_amount_adjusted' => $session->opening_amount_adjusted,
            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,
            'status' => $session->status,
            'expected_cash' => $session->expected_cash,
            'expected_cash_live' => round($expectedCashLive, 2),
            'counted_cash' => $session->counted_cash,
            'difference' => $session->difference,
            'difference_reason' => $session->difference_reason,
            'closing_notes' => $session->closing_notes,
            'movements' => $session->cashMovements,
            'denominations' => $session->cashSessionDenominations,
            'totals_by_payment_method' => $totales,
        ];
    }
}
