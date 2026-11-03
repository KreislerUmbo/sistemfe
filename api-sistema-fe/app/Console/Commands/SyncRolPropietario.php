<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Roles\RolPropietario;
use Illuminate\Console\Command;

/**
 * T-081 — deja el rol Propietario de cada tenant con todos los permisos que existen en él (menos
 * RolPropietario::PERMISOS_SOLO_SOPORTE). Idempotente; corre en cada despliegue (deploy.sh, paso
 * 7), así una función nueva le llega sola al dueño. Solo toca el rol Propietario, nunca los demás.
 */
class SyncRolPropietario extends Command
{
    protected $signature = 'roles:sync-propietario
        {--tenant=* : id de tenant a procesar (default: todos los no archivados)}';

    protected $description = 'Sincroniza el rol Propietario con todos los permisos del tenant (menos los de solo soporte).';

    public function handle(): int
    {
        $ids = $this->option('tenant');
        $tenants = $ids
            ? Tenant::whereIn('id', $ids)->get()
            : Tenant::where('status', '!=', 'archivado')->get();

        if ($ids && $tenants->count() !== count($ids)) {
            $this->error('No existe el tenant: ' . implode(', ', array_diff($ids, $tenants->pluck('id')->all())));

            return self::FAILURE;
        }

        $resumen = [];
        foreach ($tenants as $tenant) {
            $cambios = $tenant->run(fn () => RolPropietario::sincronizar());
            if ($cambios['quitados'] !== []) {
                $this->line("  [{$tenant->id}] quitados: " . implode(', ', $cambios['quitados']));
            }
            $resumen[] = [$tenant->id, count($cambios['agregados']), count($cambios['quitados'])];
        }

        $this->table(['Tenant', 'Permisos agregados', 'Permisos quitados'], $resumen);

        return self::SUCCESS;
    }
}
