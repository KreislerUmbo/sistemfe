<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Models\Creditos\CreditoAuditoria;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Auditoría de acciones sensibles del tenant (credito_auditoria; AuditLogger es solo central). */
class AuditoriaCredito
{
    public function registrar(
        string $accion,
        Model $auditable,
        ?int $creditoId,
        ?array $antes,
        ?array $despues,
        ?string $motivo,
        ?User $usuario,
    ): void {
        CreditoAuditoria::create([
            'accion' => $accion,
            'auditable_type' => $auditable->getTable(),
            'auditable_id' => $auditable->getKey(),
            'credito_id' => $creditoId,
            'antes' => $antes,
            'despues' => $despues,
            'motivo' => $motivo,
            // 0 = proceso del sistema (job de escalamiento), sin usuario.
            'usuario_id' => $usuario?->id ?? 0,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
