<?php

namespace App\Console\Commands;

use App\Exceptions\TenantProvisioningException;
use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;

class RestoreTenant extends Command
{
    protected $signature = 'tenants:restore {tenant : ID del tenant a restaurar}';

    protected $description = 'Revierte el archivado de un tenant (vuelve a permitir login/API).';

    public function handle(TenantProvisioningService $service): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if (! $tenant) {
            $this->error("No existe ningún tenant con id '{$this->argument('tenant')}'.");

            return self::FAILURE;
        }

        try {
            $tenant = $service->restaurar($tenant);
        } catch (TenantProvisioningException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }

        $this->info("Tenant '{$tenant->id}' restaurado a estado activo.");

        return self::SUCCESS;
    }
}
