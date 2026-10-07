<?php

namespace App\Services;

// Ver config/plataforma.php — qué tenant(s) administran el catálogo central.
class PlataformaTenant
{
    public static function es(?string $tenantId): bool
    {
        return $tenantId !== null && in_array($tenantId, config('plataforma.tenants', []), true);
    }

    public static function esElActual(): bool
    {
        return tenancy()->initialized && self::es((string) tenant('id'));
    }
}
