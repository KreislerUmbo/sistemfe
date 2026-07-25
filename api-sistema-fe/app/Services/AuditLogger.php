<?php

namespace App\Services;

use App\Models\Central\CentralAuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    public function __construct(private Request $request)
    {
    }

    public function log(
        string $action,
        ?string $auditableType = null,
        ?string $auditableId = null,
        ?array $payload = null
    ): CentralAuditLog {
        return CentralAuditLog::create([
            'central_user_id' => auth('central')->id(),
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'payload' => $payload,
            'ip_address' => $this->request->ip(),
        ]);
    }
}
