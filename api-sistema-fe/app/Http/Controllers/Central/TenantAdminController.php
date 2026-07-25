<?php

namespace App\Http\Controllers\Central;

use App\Exceptions\TenantProvisioningException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\TenantProvisioningService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Panel superadmin (plan-panel-superadmin.md, Fase A) — wrapper HTTP delgado sobre
// TenantProvisioningService, misma lógica que app/Console/Commands/ProvisionTenant.php
// (CLI). Rutas protegidas por auth:central + central.token (routes/api.php).
class TenantAdminController extends Controller
{
    public function __construct(
        private TenantProvisioningService $provisioningService,
        private AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request)
    {
        $search = $request->get('search');

        $tenants = Tenant::with('domains')
            ->when($search, function ($query) use ($search) {
                $query->where('id', 'ilike', '%' . $search . '%')
                    ->orWhere('data->razon_social', 'ilike', '%' . $search . '%');
            })
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'total' => $tenants->total(),
            'paginate' => 15,
            'tenants' => collect($tenants->items())->map(fn (Tenant $t) => $this->serialize($t)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'ruc' => 'required|string',
            'razon_social' => 'required|string',
            'razon_social_comercial' => 'required|string',
            'domain' => 'required|string',
            'admin_name' => 'required|string',
            'admin_email' => 'required|email',
            // Requerido acá a propósito, a diferencia del Command CLI (donde admin
            // -password es opcional y se genera uno random si se omite): la generación
            // automática de password queda exclusiva del flujo CLI. El servicio
            // (TenantProvisioningService) sigue soportando el caso opcional sin cambios,
            // simplemente este controller nunca lo ejercita.
            'admin_password' => 'required|string|min:8',
        ]);

        try {
            $tenant = $this->provisioningService->provision($data);
        } catch (TenantProvisioningException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        $this->auditLogger->log('tenant.created', Tenant::class, $tenant->id, [
            'ruc' => $data['ruc'],
            'domain' => $data['domain'],
            'admin_email' => $data['admin_email'],
        ]);

        return response()->json([
            'tenant' => $this->serialize($tenant->fresh('domains')),
        ], 201);
    }

    public function show(string $id)
    {
        $tenant = Tenant::with('domains')->find($id);

        if (! $tenant) {
            throw new HttpException(404, 'Tenant no encontrado.');
        }

        return response()->json([
            'tenant' => $this->serialize($tenant),
        ]);
    }

    // "Archivado, no borrado" (§11.2) — bloquea login/API, conserva base y storage
    // intactos (retención legal SUNAT). Wrapper delgado sobre
    // TenantProvisioningService::archivar(), misma lógica que `tenants:archive` (CLI).
    public function archive(string $id)
    {
        $tenant = $this->resolveTenant($id);

        try {
            $tenant = $this->provisioningService->archivar($tenant);
        } catch (TenantProvisioningException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        $this->auditLogger->log('tenant.archived', Tenant::class, $tenant->id);

        return response()->json(['tenant' => $this->serialize($tenant->fresh('domains'))]);
    }

    public function restore(string $id)
    {
        $tenant = $this->resolveTenant($id);

        try {
            $tenant = $this->provisioningService->restaurar($tenant);
        } catch (TenantProvisioningException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        $this->auditLogger->log('tenant.restored', Tenant::class, $tenant->id);

        return response()->json(['tenant' => $this->serialize($tenant->fresh('domains'))]);
    }

    // Botón "Eliminar" del listado — deliberadamente estrecho, ver
    // TenantProvisioningService::eliminarSiVacio(): solo borra de verdad si el tenant
    // nunca llegó a tener Company/SunatConfig/clientes/productos/ventas. Pensado para
    // "me equivoqué al crearlo", nunca como alternativa al archivado de un tenant real.
    public function destroy(string $id)
    {
        $tenant = $this->resolveTenant($id);
        $ruc = $tenant->ruc;

        try {
            $this->provisioningService->eliminarSiVacio($tenant);
        } catch (TenantProvisioningException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        // El tenant ya no existe en `tenants` a esta altura — se audita con los datos
        // que ya teníamos en memoria antes de borrar, no con una relectura.
        $this->auditLogger->log('tenant.deleted', Tenant::class, $id, ['ruc' => $ruc]);

        return response()->json(['message' => "Tenant '{$id}' eliminado."]);
    }

    private function resolveTenant(string $id): Tenant
    {
        $tenant = Tenant::find($id);

        if (! $tenant) {
            throw new HttpException(404, 'Tenant no encontrado.');
        }

        return $tenant;
    }

    private function serialize(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'ruc' => $tenant->ruc,
            'razon_social' => $tenant->razon_social,
            'razon_social_comercial' => $tenant->razon_social_comercial,
            'status' => $tenant->status,
            'fecha_archivado' => $tenant->fecha_archivado,
            'fecha_alta' => $tenant->created_at,
            'domains' => $tenant->domains->pluck('domain'),
        ];
    }
}
