<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\TenantBackup;
use App\Models\Tenant;
use App\Services\TenantBackupService;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Fase C.1 (plan-panel-superadmin.md) — backups on-demand por tenant, gestión desde el
// panel superadmin. Corre síncrono dentro del propio request (mismo criterio que el resto
// del proyecto — "Nota de infraestructura": sin worker de colas en este entorno).
class TenantBackupController extends Controller
{
    public function __construct(private TenantBackupService $backupService)
    {
    }

    public function index(string $id)
    {
        $this->resolveTenant($id);

        $backups = TenantBackup::where('tenant_id', $id)
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json($backups);
    }

    public function store(string $id)
    {
        $tenant = $this->resolveTenant($id);

        $backup = $this->backupService->crearManual($tenant);

        return response()->json(['backup' => $backup], 201);
    }

    // Fase C.4 — re-chequeo bajo demanda de un backup ya existente (bit rot, disco
    // degradado después de creado, o backups anteriores a que este chequeo existiera).
    public function verify(string $id, string $backupId)
    {
        $backup = $this->resolveBackup($id, $backupId);

        $backup = $this->backupService->reverificar($backup);

        return response()->json(['backup' => $backup]);
    }

    private function resolveTenant(string $id): Tenant
    {
        $tenant = Tenant::find($id);

        if (! $tenant) {
            throw new HttpException(404, 'Tenant no encontrado.');
        }

        return $tenant;
    }

    private function resolveBackup(string $tenantId, string $backupId): TenantBackup
    {
        $backup = TenantBackup::where('id', $backupId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $backup) {
            throw new HttpException(404, 'Backup no encontrado para este tenant.');
        }

        return $backup;
    }
}
