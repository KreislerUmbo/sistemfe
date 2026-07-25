<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\TenantPlan;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Panel superadmin (plan-panel-superadmin.md, Fase B.2.6) — CRUD del catálogo de planes
// de suscripción (tenant_plans, conexión 'central'). La tabla existía desde B.2.1 sin
// ningún controller/ruta real — se poblaba a mano por tinker (un solo plan de prueba,
// "Plan Prueba B.2"). Sin borrado real (mismo criterio que PaymentMethodController): un
// plan "descontinuado" sigue siendo el dato correcto de las suscripciones históricas que
// ya lo usan, y tenant_subscriptions.tenant_plan_id tiene restrictOnDelete() de todos modos.
class TenantPlanController extends Controller
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function index()
    {
        $plans = TenantPlan::orderBy('precio_mensual')->get();

        return response()->json(['tenant_plans' => $plans]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $plan = TenantPlan::create($data);

        $this->auditLogger->log('tenant_plan.created', TenantPlan::class, (string) $plan->id, $data);

        return response()->json(['tenant_plan' => $plan], 201);
    }

    public function update(Request $request, string $id)
    {
        $plan = $this->resolvePlan($id);

        $data = $this->validated($request, $plan->id);

        $plan->update($data);

        $this->auditLogger->log('tenant_plan.updated', TenantPlan::class, (string) $plan->id, $data);

        return response()->json(['tenant_plan' => $plan]);
    }

    // Desactivar, no borrar — le saca la opción de elegirse para una suscripción NUEVA,
    // sin tocar ninguna suscripción existente que ya lo use.
    public function destroy(string $id)
    {
        $plan = $this->resolvePlan($id);
        $plan->update(['activo' => false]);

        $this->auditLogger->log('tenant_plan.deactivated', TenantPlan::class, (string) $plan->id, []);

        return response()->json(['tenant_plan' => $plan]);
    }

    private function resolvePlan(string $id): TenantPlan
    {
        $plan = TenantPlan::find($id);

        if (! $plan) {
            throw new HttpException(404, 'Plan no encontrado.');
        }

        return $plan;
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('central.tenant_plans', 'nombre')->ignore($ignoreId),
            ],
            'limite_usuarios' => 'nullable|integer|min:0',
            'limite_comprobantes_mes' => 'nullable|integer|min:0',
            'limite_storage_mb' => 'nullable|integer|min:0',
            'precio_mensual' => 'required|numeric|min:0',
            'activo' => 'boolean',
        ]);
    }
}
