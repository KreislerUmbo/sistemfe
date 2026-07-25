<?php

namespace App\Console\Commands;

use App\Exceptions\TenantProvisioningException;
use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;

class ArchiveTenant extends Command
{
    protected $signature = 'tenants:archive {tenant : ID del tenant a archivar}';

    protected $description = 'Marca un tenant como archivado (bloquea login/API, no toca su base ni su storage — ver plan §11.2).';

    public function handle(TenantProvisioningService $service): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if (! $tenant) {
            $this->error("No existe ningún tenant con id '{$this->argument('tenant')}'.");

            return self::FAILURE;
        }

        try {
            $tenant = $service->archivar($tenant);
        } catch (TenantProvisioningException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }

        $this->info("Tenant '{$tenant->id}' archivado. Base de datos y storage quedan intactos — solo se bloqueó el acceso.");

        return self::SUCCESS;
    }
}
