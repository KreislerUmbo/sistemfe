<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\TenantInvoice;
use App\Models\Central\TenantPaymentVoucher;
use App\Models\Central\TenantPlan;
use App\Models\Central\TenantSubscription;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\TenantInvoiceService;
use App\Services\TenantSubscriptionManagementService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Panel superadmin (plan-panel-superadmin.md, Fase B.2) — gestión de suscripciones y
// facturación de tenants. A diferencia de TenantSunatController (Fase B.3), estas tablas
// viven en la conexión 'central' directo (no en la BD propia de cada tenant) — sin
// $tenant->run(), consulta normal.
class TenantSubscriptionController extends Controller
{
    public function __construct(
        private TenantInvoiceService $invoiceService,
        private TenantSubscriptionManagementService $managementService,
        private AuditLogger $auditLogger,
    ) {}

    // Generación manual de invoice — B.2.3. El scheduled command
    // (tenants:generate-monthly-invoices) llama al mismo TenantInvoiceService, nunca
    // duplica esta lógica.
    public function generarInvoice(Request $request, string $id)
    {
        $tenant = $this->resolveTenant($id);

        $data = $request->validate([
            'periodo' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $periodo = $data['periodo'] ?? now()->format('Y-m');

        $subscription = TenantSubscription::where('tenant_id', $id)
            ->where('estado', 'activa')
            ->first();

        if (! $subscription) {
            throw new HttpException(422, 'Este tenant no tiene una suscripción activa.');
        }

        $invoice = $this->invoiceService->generarParaPeriodo($subscription, $periodo);

        $this->auditLogger->log('tenant.invoice.generated', Tenant::class, $tenant->id, [
            'periodo' => $periodo,
            'folio_interno' => $invoice->folio_interno,
            'monto' => $invoice->monto,
        ]);

        return response()->json(['invoice' => $invoice], 201);
    }

    // B.2.5 — marcar un invoice pagado manualmente (ej. pago reportado por teléfono/
    // efectivo, sin voucher de por medio). Reactiva el tenant solo si no queda ningún otro
    // invoice vencido sin pagar (TenantSubscriptionManagementService::marcarPagado()).
    public function marcarPagado(string $id, string $invoiceId)
    {
        $invoice = $this->resolveInvoice($id, $invoiceId);

        $invoice = $this->managementService->marcarPagado($invoice);

        return response()->json(['invoice' => $invoice]);
    }

    public function subirVoucher(Request $request, string $id, string $invoiceId)
    {
        $invoice = $this->resolveInvoice($id, $invoiceId);

        $request->validate([
            'voucher' => 'required|file|max:5120|mimes:jpg,jpeg,png,pdf',
        ]);

        $voucher = $this->managementService->subirVoucher($invoice, $request->file('voucher'));

        return response()->json(['voucher' => $voucher], 201);
    }

    // Verificar ES la confirmación de pago — dispara el mismo marcarPagado() (y su
    // reactivación automática si corresponde) que el endpoint manual de arriba.
    public function verificarVoucher(string $id, string $invoiceId, string $voucherId)
    {
        $invoice = $this->resolveInvoice($id, $invoiceId);
        $voucher = $this->resolveVoucher($invoice, $voucherId);

        $voucher = $this->managementService->verificarVoucher($voucher);

        return response()->json(['voucher' => $voucher]);
    }

    public function rechazarVoucher(Request $request, string $id, string $invoiceId, string $voucherId)
    {
        $invoice = $this->resolveInvoice($id, $invoiceId);
        $voucher = $this->resolveVoucher($invoice, $voucherId);

        $data = $request->validate([
            'motivo' => 'required|string|max:500',
        ]);

        $voucher = $this->managementService->rechazarVoucher($voucher, $data['motivo']);

        return response()->json(['voucher' => $voucher]);
    }

    // Suspensión manual (acción de supervisor desde el panel) — distinta de la suspensión
    // automática por falta de pago (B.2.4, tenants:check-overdue-payments).
    public function suspender(Request $request, string $id)
    {
        $tenant = $this->resolveTenant($id);

        $data = $request->validate([
            'motivo' => 'required|string|max:500',
        ]);

        $tenant = $this->managementService->suspenderManual($tenant, $data['motivo']);

        return response()->json(['tenant' => $tenant]);
    }

    public function reactivar(string $id)
    {
        $tenant = $this->resolveTenant($id);

        $tenant = $this->managementService->reactivarManual($tenant);

        return response()->json(['tenant' => $tenant]);
    }

    // Fase B.2.6 — crear la suscripción de un tenant (asignarle un plan por primera vez).
    // Hasta esta fase no existía ningún endpoint para esto: las únicas 2 suscripciones
    // reales del sistema (sandbox, umbo no tenía ninguna) se habían creado a mano por
    // tinker. Solo permite crear si el tenant no tiene ya una suscripción 'activa' —
    // cambiar de plan es update(), no una segunda fila.
    public function store(Request $request, string $id)
    {
        $tenant = $this->resolveTenant($id);

        $yaActiva = TenantSubscription::where('tenant_id', $id)->where('estado', 'activa')->exists();
        if ($yaActiva) {
            throw new HttpException(422, 'Este tenant ya tiene una suscripción activa — para cambiarle el plan, editá la suscripción existente.');
        }

        $data = $this->validatedSubscription($request);
        $plan = $this->resolvePlan($data['tenant_plan_id']);

        if (! $plan->activo) {
            throw new HttpException(422, 'No se puede asignar un plan inactivo a una suscripción nueva.');
        }

        $subscription = TenantSubscription::create([
            'tenant_id' => $id,
            'tenant_plan_id' => $plan->id,
            'monto_mensual_override' => $data['monto_mensual_override'] ?? null,
            'dia_corte' => $data['dia_corte'] ?? null,
            'dias_gracia_suspension' => $data['dias_gracia_suspension'] ?? null,
            'facturacion_automatica' => $data['facturacion_automatica'] ?? false,
            'estado' => 'activa',
        ]);

        $this->auditLogger->log('tenant.subscription.created', Tenant::class, $tenant->id, [
            'tenant_plan_id' => $plan->id,
            'plan_nombre' => $plan->nombre,
        ]);

        return response()->json(['subscription' => $subscription->load('plan')], 201);
    }

    // Cambiar el plan (o dia_corte/override/etc.) de la suscripción activa ya existente
    // — nunca crea una fila nueva, nunca toca invoices ya generados con el monto viejo.
    public function update(Request $request, string $id)
    {
        $tenant = $this->resolveTenant($id);

        $subscription = TenantSubscription::where('tenant_id', $id)
            ->where('estado', 'activa')
            ->first();

        if (! $subscription) {
            throw new HttpException(422, 'Este tenant no tiene una suscripción activa.');
        }

        $data = $this->validatedSubscription($request);
        $plan = $this->resolvePlan($data['tenant_plan_id']);

        // Un plan inactivo sigue siendo válido acá si es el mismo que ya tenía asignado
        // (permite corregir dia_corte/override sin forzar un cambio de plan de paso).
        if (! $plan->activo && $plan->id !== $subscription->tenant_plan_id) {
            throw new HttpException(422, 'No se puede asignar un plan inactivo.');
        }

        $subscription->update([
            'tenant_plan_id' => $plan->id,
            'monto_mensual_override' => $data['monto_mensual_override'] ?? null,
            'dia_corte' => $data['dia_corte'] ?? null,
            'dias_gracia_suspension' => $data['dias_gracia_suspension'] ?? null,
            'facturacion_automatica' => $data['facturacion_automatica'] ?? false,
        ]);

        $this->auditLogger->log('tenant.subscription.updated', Tenant::class, $tenant->id, [
            'tenant_plan_id' => $plan->id,
            'plan_nombre' => $plan->nombre,
        ]);

        return response()->json(['subscription' => $subscription->fresh()->load('plan')]);
    }

    public function show(string $id)
    {
        $this->resolveTenant($id);

        $subscription = TenantSubscription::where('tenant_id', $id)
            ->where('estado', 'activa')
            ->with('plan')
            ->first();

        // Fase D, Paso 2 (plan-panel-superadmin.md) — with('vouchers') agregado: sin esto,
        // el tab Suscripción del panel no podía mostrar/verificar vouchers subidos en
        // sesiones anteriores (la relación TenantInvoice::vouchers() ya existía en el
        // modelo, nunca se eager-cargaba acá). Aditivo puro — no cambia ningún otro campo.
        $invoices = TenantInvoice::where('tenant_id', $id)
            ->orderByDesc('created_at')
            ->with('vouchers')
            ->get();

        return response()->json([
            'subscription' => $subscription,
            'invoices' => $invoices,
        ]);
    }


    public function toggleAutomatica(string $id)
    {
        $this->resolveTenant($id);

        $subscription = TenantSubscription::where('tenant_id', $id)
            ->where('estado', 'activa')
            ->first();

        if (! $subscription) {
            throw new HttpException(422, 'Este tenant no tiene una suscripción activa.');
        }

        $subscription = $this->managementService->toggleAutomatica($subscription);

        return response()->json(['subscription' => $subscription]);
    }

    private function resolveTenant(string $id): Tenant
    {
        $tenant = Tenant::find($id);

        if (! $tenant) {
            throw new HttpException(404, 'Tenant no encontrado.');
        }

        return $tenant;
    }

    // tenant_id se valida siempre junto al id del invoice — evita que alguien con acceso
    // al panel pero apuntando mal la URL (o probando ids ajenos) opere sobre el invoice de
    // otro tenant.
    private function resolveInvoice(string $tenantId, string $invoiceId): TenantInvoice
    {
        $invoice = TenantInvoice::where('id', $invoiceId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $invoice) {
            throw new HttpException(404, 'Invoice no encontrado para este tenant.');
        }

        return $invoice;
    }

    private function resolveVoucher(TenantInvoice $invoice, string $voucherId): TenantPaymentVoucher
    {
        $voucher = TenantPaymentVoucher::where('id', $voucherId)
            ->where('tenant_invoice_id', $invoice->id)
            ->first();

        if (! $voucher) {
            throw new HttpException(404, 'Voucher no encontrado para este invoice.');
        }

        return $voucher;
    }

    private function resolvePlan(int $id): TenantPlan
    {
        $plan = TenantPlan::find($id);

        if (! $plan) {
            throw new HttpException(422, 'Plan no encontrado.');
        }

        return $plan;
    }

    private function validatedSubscription(Request $request): array
    {
        return $request->validate([
            'tenant_plan_id' => 'required|integer',
            'monto_mensual_override' => 'nullable|numeric|min:0',
            'dia_corte' => 'nullable|integer|between:1,31',
            'dias_gracia_suspension' => 'nullable|integer|min:0',
            'facturacion_automatica' => 'boolean',
        ]);
    }
}
