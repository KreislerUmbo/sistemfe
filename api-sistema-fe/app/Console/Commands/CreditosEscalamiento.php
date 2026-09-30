<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Creditos\EscalamientoService;
use App\Services\Creditos\Reloj;
use Illuminate\Console\Command;

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

        foreach ($tenants as $tenant) {
            $castigados = $tenant->run(fn () => $escalamiento->ejecutar($reloj->hoy()));
            $this->info("{$tenant->id}: {$castigados} crédito(s) castigado(s).");
        }

        return self::SUCCESS;
    }
}
