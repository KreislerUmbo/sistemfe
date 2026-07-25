<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\TenantBackup;
use App\Models\Central\TenantRestore;
use App\Models\Tenant;
use App\Services\TenantRestoreService;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Fase C.3 (plan-panel-superadmin.md) — restauración con fricción intencional: preview
// (paso 1, genera token) + confirm (paso 2, ejecuta de verdad). Nunca un solo POST.
class TenantRestoreController extends Controller
{
    public function __construct(private TenantRestoreService $restoreService)
    {
    }

    public function preview(string $id, string $backupId)
    {
        $tenant = $this->resolveTenant($id);
        $backup = $this->resolveBackup($id, $backupId);

        $restore = $this->restoreService->crearPreview($tenant, $backup);

        return response()->json([
            'restore' => [
                'id' => $restore->id,
                'confirm_token' => $restore->confirm_token,
                'confirm_token_expires_at' => $restore->confirm_token_expires_at,
            ],
            'backup' => [
                'id' => $backup->id,
                'tipo' => $backup->tipo,
                'size_bytes' => $backup->size_bytes,
                'created_at' => $backup->created_at,
            ],
        ], 201);
    }

    public function confirm(string $id, string $confirmToken)
    {
        $this->resolveTenant($id);
        $restore = $this->resolveRestoreByToken($id, $confirmToken);

        $restore = $this->restoreService->confirmarYEjecutar($restore);

        return response()->json(['restore' => $restore]);
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

    private function resolveRestoreByToken(string $tenantId, string $confirmToken): TenantRestore
    {
        $restore = TenantRestore::where('confirm_token', $confirmToken)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $restore) {
            throw new HttpException(404, 'Token de confirmación inválido para este tenant.');
        }

        return $restore;
    }
}
