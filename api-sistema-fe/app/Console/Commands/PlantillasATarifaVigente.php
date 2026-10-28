<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AgenciaViajes\PlantillasATarifaVigenteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

// T-028 — pasa los tours que quedaron con una versión vieja de tarifa a la
// vigente. Ver PlantillasATarifaVigenteService. Mismo patrón de tenants que
// agencia-viajes:separar-grupos-duplicados.
class PlantillasATarifaVigente extends Command
{
    protected $signature = 'agencia-viajes:plantillas-a-tarifa-vigente
        {--simular : Solo muestra lo que cambiaría, no escribe nada}
        {--tenant=* : Id(s) de tenant. Por defecto: todos los tenants giro=agencia_viajes, no archivados}';

    protected $description = 'Reapunta los tours cuya tarifa ya no está vigente a la tarifa vigente equivalente (T-028).';

    public function handle(PlantillasATarifaVigenteService $servicio): int
    {
        $simular = (bool) $this->option('simular');
        $ids = $this->option('tenant');
        $tenants = ! empty($ids)
            ? Tenant::whereIn('id', $ids)->get()
            : Tenant::where('giro', 'agencia_viajes')->where('status', '!=', 'archivado')->get();

        if ($tenants->isEmpty()) {
            $this->error('No se encontró ningún tenant (revisa --tenant o el filtro giro=agencia_viajes).');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $resultado = $tenant->run(function () use ($servicio, $simular) {
                if (! Schema::hasTable('ajustes_plantilla_tarifa')) {
                    return null;
                }

                return $servicio->reapuntar($simular);
            });

            if ($resultado === null) {
                $this->warn("Tenant '{$tenant->id}': sin el vertical de agencia (o falta migrar), se omite.");

                continue;
            }

            $verbo = $simular ? 'reapuntaría' : 'reapuntó';
            $this->info("Tenant '{$tenant->id}': {$verbo} ".count($resultado['cambios']).' ítem(s) de tour; '.count($resultado['revisar']).' para revisar a mano.');
            if ($resultado['cambios']) {
                $this->table(
                    ['Ítem de tour', 'Tarifa anterior', 'Tarifa nueva'],
                    array_map(fn ($c) => [$c['item_id'], $c['tarifa_anterior_id'], $c['tarifa_nueva_id']], $resultado['cambios'])
                );
            }
            if ($resultado['revisar']) {
                $this->table(
                    ['Ítem de tour', 'Tarifa anterior', 'Motivo'],
                    array_map(fn ($c) => [$c['item_id'], $c['tarifa_anterior_id'], $c['motivo']], $resultado['revisar'])
                );
            }
        }

        return self::SUCCESS;
    }
}
