<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Cash\CompletarMonedaCajaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

// T-009 — completa la moneda de los movimientos de caja viejos. Ver CompletarMonedaCajaService.
// Caja es de todos los giros: recorre todos los tenants no archivados.
class CompletarMonedaCaja extends Command
{
    protected $signature = 'caja:completar-moneda
        {--simular : Solo muestra lo que cambiaría, no escribe nada}
        {--tenant=* : Id(s) de tenant. Por defecto: todos los no archivados}';

    protected $description = 'Completa la moneda (PEN/USD) de los movimientos de caja anteriores a la columna currency (T-009).';

    public function handle(CompletarMonedaCajaService $servicio): int
    {
        $simular = (bool) $this->option('simular');
        $ids = $this->option('tenant');
        $tenants = ! empty($ids)
            ? Tenant::whereIn('id', $ids)->get()
            : Tenant::where('status', '!=', 'archivado')->get();

        if ($tenants->isEmpty()) {
            $this->error('No se encontró ningún tenant.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $resultado = $tenant->run(function () use ($servicio, $simular) {
                if (! Schema::hasTable('ajustes_moneda_caja') || ! Schema::hasColumn('cash_movements', 'currency')) {
                    return null;
                }

                return $servicio->completar($simular);
            });

            if ($resultado === null) {
                $this->warn("Tenant '{$tenant->id}': falta migrar (tenants:migrate-verticales), se omite.");

                continue;
            }

            $verbo = $simular ? 'cambiaría' : 'cambió';
            $this->info("Tenant '{$tenant->id}': {$verbo} ".count($resultado['cambios']).' movimiento(s); '.count($resultado['revisar']).' para revisar a mano.');
            if ($resultado['cambios']) {
                $this->table(
                    ['Movimiento', 'Moneda anterior', 'Moneda nueva'],
                    array_map(fn ($c) => [$c['movimiento_id'], $c['moneda_anterior'], $c['moneda_nueva']], $resultado['cambios'])
                );
            }
            if ($resultado['revisar']) {
                $this->table(
                    ['Movimiento', 'Motivo'],
                    array_map(fn ($c) => [$c['movimiento_id'], $c['motivo']], $resultado['revisar'])
                );
            }
        }

        return self::SUCCESS;
    }
}
