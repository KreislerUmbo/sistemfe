<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// T-029. SOLO LECTURA — no escribe nada. Lista los ítems de alternativas/reservas vigentes que
// quedaron con tip_afe_igv nulo y sin tarifa de proveedor (guías y ajustes de redondeo de tours
// cargados desde plantilla antes de T-029): al facturar caen al fijo '10'/'nacional' en vez del
// default de la agencia. No se corrigen solos; se revisan con el usuario.
class DiagnosticarItemsSinIgv extends Command
{
    protected $signature = 'agencia-viajes:diagnosticar-items-sin-igv
        {--tenant=* : Id(s) de tenant. Por defecto: todos los tenants giro=agencia_viajes, no archivados}
        {--incluir-archivados : Incluye también tenants con status=archivado}';

    protected $description = 'DIAGNÓSTICO DE SOLO LECTURA: ítems de alternativas/reservas activas sin tip_afe_igv y sin tarifa de proveedor (T-029). No modifica nada.';

    public function handle(): int
    {
        $tenants = $this->resolverTenants();

        if ($tenants->isEmpty()) {
            $this->error('No se encontró ningún tenant para diagnosticar (revisa --tenant o el filtro giro=agencia_viajes).');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $resultado = $tenant->run(function () {
                if (! Schema::hasTable('alternativa_items') || ! Schema::hasTable('reserva_items')) {
                    return null;
                }

                return $this->buscar();
            });

            if ($resultado === null) {
                $this->warn("Tenant '{$tenant->id}': sin el vertical de agencia, se omite.");

                continue;
            }

            $this->info("Tenant '{$tenant->id}': {$resultado['alternativa']->count()} ítem(s) de alternativa y {$resultado['reserva']->count()} de reserva sin IGV");

            if ($resultado['alternativa']->isNotEmpty()) {
                $this->table(['Alternativa ítem', 'Alternativa', 'Origen', 'Descripción'], $resultado['alternativa']->map(fn ($f) => [
                    $f->id, $f->alternativa_id, $f->origen_tipo, mb_strimwidth((string) $f->descripcion_manual, 0, 40, '…'),
                ])->all());
            }

            if ($resultado['reserva']->isNotEmpty()) {
                $this->table(['Reserva ítem', 'Reserva', 'Origen', 'Descripción'], $resultado['reserva']->map(fn ($f) => [
                    $f->id, $f->reserva_id, $f->origen_tipo ?? '—', mb_strimwidth((string) ($f->descripcion ?? ''), 0, 40, '…'),
                ])->all());
            }
        }

        return self::SUCCESS;
    }

    private function buscar(): array
    {
        $alternativa = DB::table('alternativa_items as ai')
            ->join('alternativas as a', 'a.id', '=', 'ai.alternativa_id')
            ->whereNull('ai.tip_afe_igv')
            ->whereNull('ai.proveedor_tarifa_id')
            ->whereNull('ai.opcion_hotel_tarifa_id')
            ->where('a.estado', '!=', 'reemplazada')
            ->orderBy('ai.id')
            ->get(['ai.id', 'ai.alternativa_id', 'ai.origen_tipo', 'ai.descripcion_manual']);

        $reserva = DB::table('reserva_items as ri')
            ->join('reserva as r', 'r.id', '=', 'ri.reserva_id')
            ->leftJoin('alternativa_items as ai', 'ai.id', '=', 'ri.alternativa_item_id')
            ->whereNull('ri.tip_afe_igv')
            ->whereNull('ri.proveedor_tarifa_id')
            ->whereNull('ri.opcion_hotel_tarifa_id')
            ->where('r.estado', '!=', 'cancelada')
            ->orderBy('ri.id')
            ->get(['ri.id', 'ri.reserva_id', 'ai.origen_tipo', 'ai.descripcion_manual as descripcion']);

        return ['alternativa' => $alternativa, 'reserva' => $reserva];
    }

    private function resolverTenants()
    {
        $idsExplicitos = $this->option('tenant');

        if (! empty($idsExplicitos)) {
            return Tenant::whereIn('id', $idsExplicitos)->get();
        }

        return Tenant::where('giro', 'agencia_viajes')
            ->when(! $this->option('incluir-archivados'), fn ($q) => $q->where('status', '!=', 'archivado'))
            ->get();
    }
}
