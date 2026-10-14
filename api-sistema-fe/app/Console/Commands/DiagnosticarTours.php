<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AgenciaViajes\DiagnosticoToursService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

// Fila 13, fase 1a (plan-tours-venta-simple-costo-real.md §3.11, N15). SOLO
// LECTURA — no escribe nada, en ningún tenant. Por tour: uso actual y
// propuesto, precio cargado, suma de costos y ventas de sus tarifas, si
// mezcla tipos de IGV, si tiene guía o servicios privados, si está vinculado
// a mayoristas, y la propuesta de conversión (precio y costo) para que el
// usuario la decida tour por tour en la sesión 1b. Mismo patrón de tenants
// que agencia-viajes:diagnosticar-fechas-reserva.
class DiagnosticarTours extends Command
{
    protected $signature = 'agencia-viajes:diagnosticar-tours
        {--tenant=* : Id(s) de tenant. Por defecto: todos los tenants giro=agencia_viajes, no archivados}
        {--incluir-archivados : Incluye también tenants con status=archivado}
        {--json : Imprime el detalle completo en JSON en vez de la tabla}';

    protected $description = 'DIAGNÓSTICO DE SOLO LECTURA: propuesta de conversión de los tours existentes al modelo de precio por persona (fila 13). No modifica nada.';

    public function handle(DiagnosticoToursService $diagnostico): int
    {
        $tenants = $this->resolverTenants();

        if ($tenants->isEmpty()) {
            $this->error('No se encontró ningún tenant para diagnosticar (revisa --tenant o el filtro giro=agencia_viajes).');

            return self::FAILURE;
        }

        $resultado = [];

        foreach ($tenants as $tenant) {
            $filas = $tenant->run(function () use ($diagnostico) {
                if (! Schema::hasTable('paquetes_plantilla')) {
                    return null;
                }

                return $diagnostico->diagnosticar();
            });

            if ($filas === null) {
                $this->warn("Tenant '{$tenant->id}': sin el vertical de agencia, se omite.");

                continue;
            }

            $resultado[$tenant->id] = $filas;

            if ($this->option('json')) {
                continue;
            }

            $this->info("Tenant '{$tenant->id}': ".count($filas).' tour(s)');
            $this->table(
                ['ID', 'Código', 'Nombre', 'Tipo', 'Uso actual', 'Uso propuesto', 'Mayoristas', 'Precio', 'Costo tarifas', 'Venta tarifas', 'IGV', 'Guía', 'Privados', 'Precio prop.', 'Costo prop.'],
                array_map(fn ($f) => [
                    $f['id'], $f['codigo'], mb_strimwidth($f['nombre'], 0, 30, '…'), $f['tipo'], $f['uso_actual'], $f['uso_propuesto'],
                    $f['vinculado_mayoristas'], $f['precio_cargado'] ?? '—', $f['suma_costo_tarifas'] ?? '—', $f['suma_venta_tarifas'] ?? '—',
                    ($f['igv_tipos'] ?: '—').($f['igv_mezclado'] ? ' ⚠' : ''), $f['tiene_guia'] ? 'sí' : '', $f['tiene_privados'] ? 'sí' : '',
                    $f['precio_propuesto'] ?? '—', $f['costo_propuesto'] ?? '—',
                ], $filas)
            );

            foreach ($filas as $f) {
                foreach ($f['avisos'] as $aviso) {
                    $this->line("  #{$f['id']} {$f['nombre']}: {$aviso}");
                }
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
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
