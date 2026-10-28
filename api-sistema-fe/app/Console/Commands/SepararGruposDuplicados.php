<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AgenciaViajes\SepararGruposDuplicadosService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

// T-024 — separa las comparaciones de hoteles que una alternativa duplicada
// (antes del fix) compartía con la original. Ver SepararGruposDuplicadosService.
// Mismo patrón de tenants que agencia-viajes:diagnosticar-tours.
class SepararGruposDuplicados extends Command
{
    protected $signature = 'agencia-viajes:separar-grupos-duplicados
        {--simular : Solo muestra lo que cambiaría, no escribe nada}
        {--tenant=* : Id(s) de tenant. Por defecto: todos los tenants giro=agencia_viajes, no archivados}';

    protected $description = 'Da un grupo_opcion_id propio a cada alternativa que comparte su comparación de hoteles con otra (T-024).';

    public function handle(SepararGruposDuplicadosService $servicio): int
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
            $cambios = $tenant->run(function () use ($servicio, $simular) {
                if (! Schema::hasTable('ajustes_grupo_opcion')) {
                    return null;
                }

                return $servicio->separar($simular);
            });

            if ($cambios === null) {
                $this->warn("Tenant '{$tenant->id}': sin el vertical de agencia (o falta migrar), se omite.");

                continue;
            }

            $verbo = $simular ? 'separaría' : 'separó';
            $this->info("Tenant '{$tenant->id}': {$verbo} ".count($cambios).' grupo(s) compartido(s).');
            if ($cambios) {
                $this->table(
                    ['Grupo compartido', 'Alternativa', 'Filas', 'Grupo nuevo'],
                    array_map(fn ($c) => [$c['grupo'], $c['alternativa_id'], $c['filas'], $c['grupo_nuevo'] ?? '(simulación)'], $cambios)
                );
            }
        }

        return self::SUCCESS;
    }
}
