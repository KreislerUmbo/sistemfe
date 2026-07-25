<?php

namespace App\Console\Commands;

use App\Services\TenantBackupService;
use Illuminate\Console\Command;

class RunAutomaticBackups extends Command
{
    protected $signature = 'tenants:run-automatic-backups';

    protected $description = 'Genera un backup automático por tenant no archivado (uno por día, idempotente), poda backups automáticos vencidos según retención y notifica fallos.';

    public function handle(TenantBackupService $service): int
    {
        $resumen = $service->generarAutomaticoParaTodos();

        $this->info(
            "{$resumen['nuevos']} backup(s) nuevo(s), " .
            "{$resumen['ya_existia']} ya existía(n) hoy, " .
            "{$resumen['fallidos']} fallido(s)."
        );

        return self::SUCCESS;
    }
}
