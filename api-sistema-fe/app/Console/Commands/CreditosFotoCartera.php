<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Creditos\Reloj;
use App\Services\Creditos\Reportes\FotoCarteraService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Módulo Créditos (04d) — foto diaria de cartera por tenant de giro 'creditos' (no archivados),
 * al final del día de Lima. Un tenant que falla no deja sin foto a los demás.
 */
class CreditosFotoCartera extends Command
{
    protected $signature = 'creditos:foto-cartera
        {--tenant=* : Id(s) de tenant. Por defecto: todos los de giro creditos no archivados}';

    protected $description = 'Guarda la foto diaria de la cartera de créditos (saldos, atraso, asesor y cobrador).';

    public function handle(FotoCarteraService $fotos, Reloj $reloj): int
    {
        $tenants = Tenant::where('giro', 'creditos')
            ->where('status', '!=', 'archivado')
            ->when($this->option('tenant'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get();

        $fallidos = 0;
        foreach ($tenants as $tenant) {
            try {
                $total = $tenant->run(fn () => $fotos->tomar($reloj->hoy()));
                $this->info("{$tenant->id}: {$total} crédito(s) en la foto.");
            } catch (\Throwable $e) {
                $fallidos++;
                Log::error('creditos:foto-cartera — falló el tenant', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
                $this->error("{$tenant->id}: falló ({$e->getMessage()}).");
            }
        }

        return $fallidos === 0 ? self::SUCCESS : self::FAILURE;
    }
}
