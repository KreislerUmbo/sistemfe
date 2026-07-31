<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class MigrateVerticalesPendientes extends Command
{
    protected $signature = 'tenants:migrate-verticales';

    /**
     * Reemplaza, de acá en adelante, al uso de `php artisan tenants:migrate` a secas
     * como comando de mantenimiento para aplicar migraciones nuevas sobre tenants ya
     * provisionados. Causa: config/tenancy.php `migration_parameters.--path` está
     * hardcodeado a tenant/core/ — ese comando genérico (sin --path explícito) nunca
     * corre tenant/verticals/*, para ningún tenant, sin importar su giro. Bug
     * preexistente desde el primer vertical (Sesión 2), compensado hasta ahora con
     * migración manual cada sesión sin que nadie notara que el mecanismo automático
     * estaba roto — ver arquitectura-multitenant-backend.md.
     *
     * Distinto del camino de provisioning inicial
     * (TenantProvisioningService::provision()), que sí funciona bien porque usa un
     * --path explícito por tenant según su giro en el momento de crearlo.
     *
     * Idempotente por diseño: Laravel trackea qué migración ya corrió por tenant en su
     * propia tabla `migrations` (una por base física de tenant) — correrlo dos veces
     * nunca duplica nada.
     */
    protected $description = 'Aplica migraciones pendientes de tenant/core/ (todos) y tenant/verticals/{giro}/ (agrupado por giro) a los tenants ya provisionados.';

    public function handle(TenantProvisioningService $service): int
    {
        $this->info('Corriendo tenant/core/ para todos los tenants...');
        Artisan::call('tenants:migrate', [], $this->output);

        $porGiro = Tenant::query()
            ->select('id', 'giro')
            ->get()
            ->groupBy('giro');

        $resumen = [];

        foreach ($porGiro as $giro => $tenants) {
            if (empty($giro)) {
                $resumen[] = [$giro ?: '(vacío)', $tenants->count(), 'No', 'giro vacío'];

                continue;
            }

            $path = $service->rutaVertical($giro);

            if ($path === null) {
                $resumen[] = [$giro, $tenants->count(), 'No', 'sin carpeta de vertical (o vacía)'];

                continue;
            }

            $this->info("Corriendo tenant/verticals/ para giro '{$giro}' ({$tenants->count()} tenant(s))...");

            Artisan::call('tenants:migrate', [
                '--tenants' => $tenants->pluck('id')->all(),
                '--path' => [$path],
                '--realpath' => true,
                '--force' => true,
            ], $this->output);

            $resumen[] = [$giro, $tenants->count(), 'Sí', $path];
        }

        $this->newLine();
        $this->table(['Giro', 'Tenants', 'Corrida', 'Detalle'], $resumen);

        return self::SUCCESS;
    }
}
