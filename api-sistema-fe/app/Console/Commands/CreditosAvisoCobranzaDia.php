<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Creditos\Avisos\AvisoCobranzaService;
use App\Services\Creditos\Avisos\UrlFrontendTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Módulo Créditos (Fase 5, 05-avisos-cobranza.md) — aviso diario "lo que cobras hoy" por tenant
 * de giro 'creditos' (no archivados). Corre cada 15 minutos: cada tenant decide su hora y días
 * en la configuración, y credito_avisos evita repetir el aviso del día. Mismo patrón de
 * recorrido que creditos:escalamiento (un tenant que falla no frena a los demás).
 */
class CreditosAvisoCobranzaDia extends Command
{
    protected $signature = 'creditos:aviso-cobranza-dia
        {--tenant=* : Id(s) de tenant. Por defecto: todos los de giro creditos no archivados}';

    protected $description = 'Envía a cada usuario que cobra el aviso de lo que tiene que cobrar hoy.';

    public function handle(AvisoCobranzaService $avisos, UrlFrontendTenant $url): int
    {
        $tenants = Tenant::where('giro', 'creditos')
            ->where('status', '!=', 'archivado')
            ->when($this->option('tenant'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get();

        $fallidos = 0;
        foreach ($tenants as $tenant) {
            try {
                $r = $tenant->run(fn () => $avisos->ejecutar($url->agendaDeHoy()));
                $this->info($r['corrio']
                    ? "{$tenant->id}: {$r['enviados']} enviado(s), {$r['omitidos']} omitido(s), {$r['fallidos']} fallido(s)."
                    : "{$tenant->id}: no corre ({$r['motivo']}).");
                if ($r['fallidos'] > 0) {
                    $fallidos++;
                }
            } catch (\Throwable $e) {
                $fallidos++;
                Log::error('creditos:aviso-cobranza-dia — falló el tenant', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
                $this->error("{$tenant->id}: falló ({$e->getMessage()}).");
            }
        }

        return $fallidos === 0 ? self::SUCCESS : self::FAILURE;
    }
}
