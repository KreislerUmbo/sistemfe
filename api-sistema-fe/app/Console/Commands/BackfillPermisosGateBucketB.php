<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\GateBucketBPermisos;
use Illuminate\Console\Command;

// Fase 0c, Parte 2 (docs/planning/claude/shadow-mode-bucket-b-fase0c.md) —
// backfill previo a activar el gate real de Bucket B. Correr ANTES (o en el
// mismo deploy) que el cambio de shadow.permission: -> permission: en
// routes/api.php, o los roles que hoy usan esos flujos sin permiso explícito
// empezarían a recibir 403. Ver GateBucketBPermisos para el criterio.
class BackfillPermisosGateBucketB extends Command
{
    protected $signature = 'permisos:backfill-gate-bucket-b
        {tenant?* : id(s) de tenant (default: todos los provisionados)}
        {--dry-run : muestra qué se crearía/otorgaría sin escribir nada}';

    protected $description = 'Crea los permisos nuevos de Bucket B y los otorga a los roles/usuarios que ya usan esos flujos (Fase 0c). No quita ningún permiso.';

    public function handle(GateBucketBPermisos $servicio): int
    {
        $ids = $this->argument('tenant');
        $tenants = $ids ? Tenant::whereIn('id', $ids)->get() : Tenant::all();

        if ($ids && $tenants->count() !== count($ids)) {
            $this->error('No existe: ' . implode(', ', array_diff($ids, $tenants->pluck('id')->all())));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($tenants as $tenant) {
            $resultado = $tenant->run(fn () => $servicio->aplicar($dryRun));

            $this->line('');
            $this->info("== {$tenant->id}" . ($dryRun ? ' (dry-run)' : ''));
            $this->line('Permisos creados: ' . ($resultado['creados'] ? implode(', ', $resultado['creados']) : 'ninguno (ya existían)'));

            if ($resultado['otorgados']) {
                $this->table(
                    ['Tipo', 'Rol/Usuario', 'Permiso otorgado'],
                    array_map(fn ($o) => [$o['tipo'], $o['nombre'], $o['permiso']], $resultado['otorgados'])
                );
            } else {
                $this->line('Otorgados: ninguno.');
            }
        }

        return self::SUCCESS;
    }
}
