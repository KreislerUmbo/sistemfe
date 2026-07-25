<?php

namespace App\Http\Controllers\Cash;

use App\Exports\CashMovementsExport;
use App\Http\Controllers\Controller;
use App\Models\Cash\CashConcept;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Cash\Supplier;
use App\Models\Client\Client;
use App\Models\Company;
use App\Services\CashCorrectionService;
use App\Services\CashVisibilityResolver;
use App\Services\ExpectedCashCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Módulo Caja — Fase 4 (plan-modulo-caja.md §5 regla #1, §6). Ingresos/
// egresos manuales. Reutiliza el mismo patrón de corrección que Fase 3
// (SaleController::prepararSincronizacionCaja/aplicarSincronizacionCaja):
// nunca se edita/borra un cash_movement real, se genera un 'correction' +
// (si es edición) un movimiento nuevo con el dato correcto. Fase 5 agrega
// export() (mismo criterio de un-controller-por-recurso: movimientos viven
// acá, sesiones en CashSessionController).
class CashMovementController extends Controller
{
    public function __construct(
        private ExpectedCashCalculator $expectedCashCalculator,
        private CashVisibilityResolver $visibility,
        private CashCorrectionService $cashCorrection,
    ) {
    }

    // ── Registrar ingreso/egreso manual ──────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'type'                  => 'required|string|in:manual_income,manual_expense',
            'payment_method_id'     => 'required|integer',
            'amount'                => 'required|numeric|min:0.01',
            'concept_id'            => 'required|integer',
            'description'           => 'required|string|min:1',
            'counterparty_type'     => 'nullable|string|in:cliente,proveedor,empleado,socio,otro',
            'counterparty_id'       => 'nullable|integer',
            'counterparty_name'     => 'nullable|string',
            'counterparty_document' => 'nullable|string',
            'attachment'            => 'nullable|file|max:10240',
        ]);

        $tipo      = $request->type;
        $sesion    = $this->resolverSesionCajaPropia();
        $concepto  = $this->resolverConcepto($request->concept_id, $tipo);
        $metodo    = $this->resolverMetodoPago($request->payment_method_id);
        $contraparte = $this->resolverContraparte($request);
        $direccion = $tipo === 'manual_income' ? 'in' : 'out';
        $amount    = round((float) $request->amount, 2);

        $company = Company::first();
        $status  = 'confirmed';

        // Aprobación condicional — solo egresos, solo si la empresa la exige
        // y el monto supera el umbral configurado (companies, Fase 1).
        if ($tipo === 'manual_expense'
            && $company?->require_expense_approval
            && $company->max_expense_without_approval !== null
            && $amount > (float) $company->max_expense_without_approval) {
            $status = 'pending_approval';
        }

        // "No negativo" (regla #4) solo se valida si el movimiento queda
        // confirmado AHORA — uno pending_approval se revalida recién al
        // aprobarse (Paso 2), no debe bloquear su propia creación.
        if ($status === 'confirmed') {
            $this->validarNoNegativo($sesion, [
                ['metodo' => $metodo, 'direction' => $direccion, 'amount' => $amount],
            ]);
        }

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = Storage::disk('public')->putFile('cash_movements', $request->file('attachment'));
        }

        $movimiento = CashMovement::create([
            'cash_session_id'   => $sesion->id,
            'type'              => $tipo,
            'payment_method_id' => $metodo->id,
            'direction'         => $direccion,
            'amount'            => $amount,
            'concept_id'        => $concepto->id,
            'description'       => $request->description,
            'counterparty_type'     => $contraparte['counterparty_type'],
            'counterparty_id'       => $contraparte['counterparty_id'],
            'counterparty_name'     => $contraparte['counterparty_name'],
            'counterparty_document' => $contraparte['counterparty_document'],
            'attachment_path'   => $attachmentPath,
            'status'            => $status,
            'created_by'        => auth('api')->user()->id,
        ]);

        return response()->json([
            'code'    => 200,
            'message' => $status === 'pending_approval'
                ? 'Egreso registrado — pendiente de aprobación de un supervisor.'
                : 'Movimiento registrado correctamente.',
            'movement' => $movimiento->fresh(['paymentMethod', 'concept']),
        ]);
    }

    // ── Aprobar/rechazar un egreso pendiente ─────────────────────────
    public function approve(string $id)
    {
        $movimiento = CashMovement::with(['cashSession', 'paymentMethod'])->findOrFail($id);

        if ($movimiento->type !== 'manual_expense' || $movimiento->status !== 'pending_approval') {
            throw new HttpException(422, 'Este movimiento no está pendiente de aprobación.');
        }

        // Recién ahora se ejercita la validación de "no negativo" (Paso 1) —
        // pudo haber cambiado desde que se creó la solicitud.
        $this->validarNoNegativo($movimiento->cashSession, [
            ['metodo' => $movimiento->paymentMethod, 'direction' => $movimiento->direction, 'amount' => (float) $movimiento->amount],
        ]);

        $movimiento->update(['status' => 'confirmed']);

        return response()->json(['code' => 200, 'message' => 'Egreso aprobado.', 'movement' => $movimiento->fresh()]);
    }

    public function reject(string $id)
    {
        $movimiento = CashMovement::findOrFail($id);

        if ($movimiento->type !== 'manual_expense' || $movimiento->status !== 'pending_approval') {
            throw new HttpException(422, 'Este movimiento no está pendiente de aprobación.');
        }

        // rejected queda fuera de ExpectedCashCalculator (status='confirmed'
        // únicamente) — no requiere ningún ajuste adicional.
        $movimiento->update(['status' => 'rejected']);

        return response()->json(['code' => 200, 'message' => 'Egreso rechazado.', 'movement' => $movimiento->fresh()]);
    }

    // ── Editar/eliminar un movimiento manual (corrección, Fase 3 reusada) ──
    public function update(Request $request, string $id)
    {
        $request->validate([
            'payment_method_id'     => 'required|integer',
            'amount'                => 'required|numeric|min:0.01',
            'concept_id'            => 'required|integer',
            'description'           => 'required|string|min:1',
            'counterparty_type'     => 'nullable|string|in:cliente,proveedor,empleado,socio,otro',
            'counterparty_id'       => 'nullable|integer',
            'counterparty_name'     => 'nullable|string',
            'counterparty_document' => 'nullable|string',
        ]);

        ['original' => $original, 'sesionDestino' => $sesionDestino] = $this->prepararCorreccion($id);

        $tipo      = $original->type; // el tipo no cambia en una edición
        $concepto  = $this->resolverConcepto($request->concept_id, $tipo);
        $metodo    = $this->resolverMetodoPago($request->payment_method_id);
        $contraparte = $this->resolverContraparte($request);
        $direccion = $tipo === 'manual_income' ? 'in' : 'out';
        $amount    = round((float) $request->amount, 2);

        // Simula el efecto combinado (reversión del original + el nuevo
        // monto) ANTES de tocar la base — evita dejar una corrección a medio
        // aplicar si el resultado daría negativo.
        $this->validarNoNegativo($sesionDestino, [
            ['metodo' => $original->paymentMethod, 'direction' => $original->direction === 'in' ? 'out' : 'in', 'amount' => (float) $original->amount],
            ['metodo' => $metodo, 'direction' => $direccion, 'amount' => $amount],
        ]);

        DB::beginTransaction();
        try {
            $this->registrarCorreccion($original);

            $nuevo = CashMovement::create([
                'cash_session_id'   => $sesionDestino->id,
                'type'              => $tipo,
                'payment_method_id' => $metodo->id,
                'direction'         => $direccion,
                'amount'            => $amount,
                'concept_id'        => $concepto->id,
                'description'       => $request->description,
                'counterparty_type'     => $contraparte['counterparty_type'],
                'counterparty_id'       => $contraparte['counterparty_id'],
                'counterparty_name'     => $contraparte['counterparty_name'],
                'counterparty_document' => $contraparte['counterparty_document'],
                'status'            => 'confirmed',
                'created_by'        => auth('api')->user()->id,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return response()->json([
            'code'    => 200,
            'message' => 'Movimiento corregido correctamente.',
            'movement' => $nuevo->fresh(['paymentMethod', 'concept']),
        ]);
    }

    public function destroy(string $id)
    {
        ['original' => $original, 'sesionDestino' => $sesionDestino] = $this->prepararCorreccion($id);

        // Solo la reversión del original puede empujar a negativo acá (no
        // hay movimiento nuevo que sumar) — mismo helper, un solo efecto.
        $this->validarNoNegativo($sesionDestino, [
            ['metodo' => $original->paymentMethod, 'direction' => $original->direction === 'in' ? 'out' : 'in', 'amount' => (float) $original->amount],
        ]);

        DB::beginTransaction();
        try {
            $this->registrarCorreccion($original);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return response()->json(['code' => 200, 'message' => 'Movimiento eliminado correctamente.']);
    }

    // ── Buscador de contraparte (cliente/proveedor) ──────────────────
    // Mismo patrón que NotaElectronicaController::buscarVenta(): q mínimo 2
    // caracteres, ILIKE, limit 15.
    public function buscarContraparte(Request $request)
    {
        $tipo = $request->get('type');
        $q    = trim((string) $request->get('q', ''));

        if (!in_array($tipo, ['cliente', 'proveedor'], true)) {
            throw new HttpException(422, "type debe ser 'cliente' o 'proveedor'.");
        }

        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        if ($tipo === 'cliente') {
            $resultados = Client::where('state', 1)
                ->whereRaw("(COALESCE(full_name, '') || ' ' || COALESCE(n_document, '')) ILIKE ?", ["%{$q}%"])
                ->orderBy('full_name')
                ->limit(15)
                ->get(['id', 'full_name as name', 'n_document as document']);
        } else {
            $resultados = Supplier::where('is_active', true)
                ->whereRaw("(COALESCE(name, '') || ' ' || COALESCE(document, '')) ILIKE ?", ["%{$q}%"])
                ->orderBy('name')
                ->limit(15)
                ->get(['id', 'name', 'document']);
        }

        return response()->json(['results' => $resultados]);
    }

    // ── Export Excel filtrable (Fase 5, Paso 5) ──────────────────────
    // Mismos filtros que el historial de sesiones (CashSessionController::
    // index) más type/payment_method_id/concept_id. Sin límite de rango de
    // fechas (a diferencia del PDF). Visibilidad: mismo criterio que el
    // historial — sin cash.view_all, CashVisibilityResolver ignora
    // opened_by pedido y fuerza el propio user_id.
    public function export(Request $request)
    {
        $user = auth('api')->user();
        $openedBy = $this->visibility->resolverOpenedBy($user, $request->query('opened_by'));

        $query = CashMovement::query()
            ->with(['cashSession.cashRegister.branch', 'cashSession.openedByUser', 'paymentMethod', 'concept', 'createdByUser']);

        if ($openedBy !== null) {
            $query->whereHas('cashSession', fn ($q) => $q->where('opened_by', $openedBy));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }
        if ($request->filled('branch_id')) {
            $branchId = $request->query('branch_id');
            $query->whereHas('cashSession.cashRegister', fn ($q) => $q->where('branch_id', $branchId));
        }
        if ($request->filled('cash_register_id')) {
            $cashRegisterId = $request->query('cash_register_id');
            $query->whereHas('cashSession', fn ($q) => $q->where('cash_register_id', $cashRegisterId));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('payment_method_id')) {
            $query->where('payment_method_id', $request->query('payment_method_id'));
        }
        if ($request->filled('concept_id')) {
            $query->where('concept_id', $request->query('concept_id'));
        }

        $query->orderByDesc('created_at');

        $nombreArchivo = 'movimientos_caja_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new CashMovementsExport($query), $nombreArchivo);
    }

    // ══════════════════════════════════════════════════════════════════
    // Helpers privados
    // ══════════════════════════════════════════════════════════════════

    private function resolverSesionCajaPropia(): CashSession
    {
        $sesion = CashSession::where('opened_by', auth('api')->user()->id)
            ->where('status', 'open')
            ->first();

        if (!$sesion) {
            throw new HttpException(422, 'No hay una sesión de caja abierta.');
        }

        return $sesion;
    }

    private function resolverConcepto(int $conceptId, string $tipo): CashConcept
    {
        $concepto = CashConcept::where('id', $conceptId)->where('is_active', true)->first();

        if (!$concepto) {
            throw new HttpException(422, 'concept_id no existe o está inactivo.');
        }

        $direccionEsperada = $tipo === 'manual_income' ? 'in' : 'out';
        if ($concepto->direction !== $direccionEsperada) {
            throw new HttpException(
                422,
                "El concepto \"{$concepto->name}\" es de dirección '{$concepto->direction}', no coincide con type='{$tipo}'."
            );
        }

        return $concepto;
    }

    private function resolverMetodoPago(int $paymentMethodId): PaymentMethod
    {
        $metodo = PaymentMethod::where('id', $paymentMethodId)->where('is_active', true)->first();

        if (!$metodo) {
            throw new HttpException(422, 'payment_method_id no existe o está inactivo.');
        }

        return $metodo;
    }

    // Si counterparty_id viene, el nombre/documento SIEMPRE se resuelve del
    // registro real — nunca se confía en counterparty_name/counterparty_document
    // del payload en ese caso (evita que quede un snapshot falso).
    private function resolverContraparte(Request $request): array
    {
        $tipo = $request->counterparty_type;
        $id   = $request->counterparty_id;

        if (!$id) {
            return [
                'counterparty_type'     => $tipo,
                'counterparty_id'       => null,
                'counterparty_name'     => $request->counterparty_name,
                'counterparty_document' => $request->counterparty_document,
            ];
        }

        if ($tipo === 'cliente') {
            $registro = Client::find($id);
            if (!$registro) {
                throw new HttpException(422, 'counterparty_id no corresponde a un cliente real.');
            }

            return [
                'counterparty_type'     => 'cliente',
                'counterparty_id'       => $registro->id,
                'counterparty_name'     => $registro->full_name,
                'counterparty_document' => $registro->n_document,
            ];
        }

        if ($tipo === 'proveedor') {
            $registro = Supplier::find($id);
            if (!$registro) {
                throw new HttpException(422, 'counterparty_id no corresponde a un proveedor real.');
            }

            return [
                'counterparty_type'     => 'proveedor',
                'counterparty_id'       => $registro->id,
                'counterparty_name'     => $registro->name,
                'counterparty_document' => $registro->document,
            ];
        }

        throw new HttpException(422, "counterparty_id requiere counterparty_type='cliente' o 'proveedor'.");
    }

    // Simula el efecto combinado de una o más operaciones propuestas sobre
    // el efectivo esperado ANTES de persistir nada (regla de integridad #4).
    // $efectos = [['metodo' => PaymentMethod, 'direction' => 'in'|'out', 'amount' => float], ...]
    private function validarNoNegativo(CashSession $sesion, array $efectos): void
    {
        $proyectado = $this->expectedCashCalculator->compute($sesion);

        foreach ($efectos as $efecto) {
            if (!$efecto['metodo']->affects_cash_count) {
                continue;
            }
            $proyectado += $efecto['direction'] === 'in' ? $efecto['amount'] : -$efecto['amount'];
        }

        if (round($proyectado, 2) < 0) {
            throw new HttpException(422, 'Esta operación dejaría el efectivo esperado en negativo.');
        }
    }

    // Resuelve y valida el movimiento a corregir — compartido por update()/
    // destroy(). Corre ANTES de abrir transacción (mismo criterio que
    // SaleController::prepararSincronizacionCaja, Fase 3): toda decisión que
    // pueda lanzar HttpException se resuelve acá.
    private function prepararCorreccion(string $id): array
    {
        $original = CashMovement::with(['cashSession', 'paymentMethod'])->find($id);

        if (!$original) {
            throw new HttpException(404, 'Movimiento no encontrado.');
        }

        if (!in_array($original->type, ['manual_income', 'manual_expense'], true)) {
            throw new HttpException(
                422,
                "Solo se pueden editar/eliminar movimientos manuales (recibido: '{$original->type}')."
            );
        }

        if ($original->corrected_by) {
            throw new HttpException(422, 'Este movimiento ya fue corregido anteriormente — no se puede corregir dos veces.');
        }

        if ($original->cashSession->status === 'closed' && !auth('api')->user()->can('cash.close_others_session')) {
            throw new HttpException(
                403,
                'Este movimiento pertenece a una sesión de caja cerrada — corregirlo requiere permiso de supervisor.'
            );
        }

        $sesionDestino = CashSession::where('opened_by', auth('api')->user()->id)
            ->where('status', 'open')
            ->first();

        if (!$sesionDestino) {
            throw new HttpException(422, 'No hay una sesión de caja abierta para registrar la corrección.');
        }

        return ['original' => $original, 'sesionDestino' => $sesionDestino];
    }

    // Delega en CashCorrectionService (extraído en Fase 6 tras encontrar
    // este mismo patrón replicado en SaleController/CreditPaymentController
    // — un solo punto de verdad, mismo criterio que ExpectedCashCalculator).
    // $sesionDestino ya no se pasa: el servicio resuelve su propia sesión
    // destino (misma consulta, mismo resultado — prepararCorreccion() ya
    // validó antes de la transacción que existe y que el permiso alcanza;
    // update()/destroy() siguen usando SU PROPIO $sesionDestino para
    // validarNoNegativo()/el movimiento de reemplazo, sin cambios).
    private function registrarCorreccion(CashMovement $original): void
    {
        $this->cashCorrection->revertirMovimiento($original, auth('api')->user());
    }
}
