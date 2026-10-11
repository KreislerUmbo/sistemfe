<?php

declare(strict_types=1);

namespace App\Services\Creditos\Avisos;

/**
 * URL del panel del tenant actual (Fase 5). El comando programado corre sin petición, así que
 * no hay host del cual copiarla: se arma con config('creditos.url_frontend_tenant') y el
 * subdominio del tenant.
 */
final class UrlFrontendTenant
{
    public function de(string $ruta): string
    {
        $subdominio = (string) (tenancy()->tenant?->domains()->value('domain') ?? tenant('id') ?? '');
        $base = str_replace('{tenant}', $subdominio, (string) config('creditos.url_frontend_tenant'));

        return rtrim($base, '/') . '/' . ltrim($ruta, '/');
    }

    /** La Agenda abierta en "Hoy" con lo atrasado: lo mismo que trae el aviso. */
    public function agendaDeHoy(): string
    {
        return $this->de('creditos/agenda?rango=hoy&atrasados=1');
    }
}
