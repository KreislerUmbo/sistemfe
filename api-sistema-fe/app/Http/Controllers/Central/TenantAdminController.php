<?php

namespace App\Http\Controllers\Central;

use App\Exceptions\TenantProvisioningException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            // Antes ausente del todo acá (solo el Command CLI lo pedía) — el panel
            // creaba tenants sin poder elegir giro, quedando siempre en el default de
            // la migración ('retail') sin importar el negocio real. Ver
            // docs/planning/panel-superadmin/gap-editar-tenant-giro-password.md.
            'giro' => ['required', 'string', Rule::in(TenantProvisioningService::GIROS_VALIDOS)],
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
            'giro' => $data['giro'],
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

    // Edición de un tenant ya creado — hasta esta sesión no existía (ver
    // docs/planning/panel-superadmin/gap-editar-tenant-giro-password.md). 'sometimes'
    // en los 3 campos: el caller puede mandar solo lo que cambió, sin repetir el resto.
    // `domain` queda fuera a propósito (ver el documento) — no es parte de este fix.
    public function update(Request $request, string $id)
    {
        $tenant = $this->resolveTenant($id);

        $data = $request->validate([
            'razon_social' => 'sometimes|string',
            'razon_social_comercial' => 'sometimes|string',
            'giro' => ['sometimes', 'string', Rule::in(TenantProvisioningService::GIROS_VALIDOS)],
        ]);

        if (empty($data)) {
            throw new HttpException(422, 'No se recibió ningún campo para actualizar.');
        }

        $giroAnterior = $tenant->giro;

        try {
            $tenant = $this->provisioningService->actualizar($tenant, $data);
        } catch (TenantProvisioningException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        $this->auditLogger->log('tenant.updated', Tenant::class, $tenant->id, array_merge($data, [
            'giro_anterior' => $giroAnterior,
        ]));

        return response()->json(['tenant' => $this->serialize($tenant->fresh('domains'))]);
    }

    // Restablecer el password del usuario administrador del tenant (rol Super-Admin,
    // guard 'api', dentro de la base física del tenant) — antes la única vía era
    // tinker/SQL manual. Acción separada de update() a propósito (más fácil de
    // auditar), ver el documento arriba. admin_email es opcional: solo hace falta si
    // el tenant tiene más de un usuario con rol Super-Admin (ambiguo sin él).
    public function resetAdminPassword(Request $request, string $id)
    {
        $tenant = $this->resolveTenant($id);

        $data = $request->validate([
            'admin_email' => 'nullable|email',
            'new_password' => 'required|string|min:8',
        ]);

        $resultado = $tenant->run(function () use ($data) {
            // whereHas('roles', ...) en vez de User::role('Super-Admin') (scope de
            // Spatie\HasRoles): User ya declara un método de instancia `role()` propio
            // (relación legacy hacia `role_id`, ver User.php) que colisiona con el
            // nombre del scope — llamado estáticamente revienta con "Non-static method
            // ... cannot be called statically" (bug real, encontrado al verificar este
            // endpoint contra un tenant descartable, no solo por lectura de código).
            $query = User::whereHas('roles', fn ($q) => $q->where('name', 'Super-Admin'));

            if (! empty($data['admin_email'])) {
                $query->where('email', $data['admin_email']);
            }

            $admins = $query->get();

            if ($admins->isEmpty()) {
                return ['error' => 'No se encontró ningún usuario con rol Super-Admin en este tenant.'];
            }

            if ($admins->count() > 1) {
                return [
                    'error' => 'Hay más de un usuario con rol Super-Admin en este tenant — '
                        . 'especificá admin_email. Candidatos: ' . $admins->pluck('email')->implode(', ') . '.',
                ];
            }

            $admin = $admins->first();
            // password cast a 'hashed' en el modelo User — asignar en texto plano y
            // save() ya lo hashea solo, no hace falta bcrypt()/Hash::make() acá.
            $admin->password = $data['new_password'];
            $admin->save();

            return ['email' => $admin->email];
        });

        if (isset($resultado['error'])) {
            throw new HttpException(422, $resultado['error']);
        }

        $this->auditLogger->log('tenant.admin_password_reset', Tenant::class, $tenant->id, [
            'admin_email' => $resultado['email'],
        ]);

        return response()->json(['message' => "Password actualizado para {$resultado['email']}."]);
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
            'giro' => $tenant->giro,
            'tipo' => $tenant->tipo,
            'sunat_modo' => $tenant->sunat_modo,
            'status' => $tenant->status,
            'fecha_archivado' => $tenant->fecha_archivado,
            'fecha_alta' => $tenant->created_at,
            'domains' => $tenant->domains->pluck('domain'),
        ];
    }
}
