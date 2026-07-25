<?php

namespace App\Console\Commands;

use App\Services\TenantOverduePaymentService;
use Illuminate\Console\Command;

class CheckOverduePayments extends Command
{
    protected $signature = 'tenants:check-overdue-payments';

    protected $description = 'Recorre invoices vencidos: envía recordatorio/aviso de gracia y suspende tenants.status al agotar el período de gracia. Idempotente — seguro de reintentar o correr dos veces el mismo día.';

    public function handle(TenantOverduePaymentService $service): int
    {
        $resumen = $service->procesar();

        $this->info(
            "{$resumen['recordatorios']} recordatorio(s) de vencimiento, " .
            "{$resumen['avisos_gracia']} aviso(s) de mitad de gracia, " .
            "{$resumen['suspensiones']} tenant(s) suspendido(s)."
        );

        return self::SUCCESS;
    }
}
