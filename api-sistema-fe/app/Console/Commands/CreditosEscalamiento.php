<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Creditos\EscalamientoService;
use App\Services\Creditos\Reloj;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Módulo Créditos (00 1.11) — proceso diario por tenant de giro 'creditos' (no archivados):
 * castigo automático al superar dias_para_castigo. Mismo patrón de recorrido que
 * DiagnosticarFechasReserva. Programado en routes/console.php en hora de Lima.
 */
class CreditosEscalamiento extends Command
{
    protected $signature = 'creditos:escalamiento
        {--tenant=* : Id(s) de tenant. Por defecto: todos los de giro creditos no archivados}';

    protected $description = 'Castiga automáticamente los créditos que superan los días de atraso configurados.';

    public function handle(EscalamientoService $escalamiento, Reloj $reloj): int
    {
        $tenants = Tenant::where('giro', 'creditos')
            ->where('status', '!=', 'archivado')
            ->when($this->option('tenant'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get();

        // 04c.1: un tenant que falla no deja sin proceso a los demás; se informa y se sigue.
        $fallidos = 0;
        foreach ($tenants as $tenant) {
            try {
                $castigados = $tenant->run(fn () => $escalamiento->ejecutar($reloj->hoy()));
                $this->info("{$tenant->id}: {$castigados} crédito(s) castigado(s).");
            } catch (\Throwable $e) {
                $fallidos++;
                Log::error('creditos:escalamiento — falló el tenant', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
                $this->error("{$tenant->id}: falló ({$e->getMessage()}).");
            }
        }

        return $fallidos === 0 ? self::SUCCESS : self::FAILURE;
    }
}
