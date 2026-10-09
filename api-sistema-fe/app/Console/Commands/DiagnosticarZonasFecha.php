<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ZonaHoraria\CatalogoZonasFecha;
use App\Services\ZonaHoraria\DiagnosticoZonasService;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

// Homogenización de fechas, F1 (08-oct-2026). SOLO LECTURA: corre cada base dentro de una
// transacción READ ONLY de Postgres que se revierte al final — no puede escribir nada.
//
// Antes de migrar los datos a UTC (F3) hay que saber, en cada servidor y tenant, si cada
// tabla está como dice CatalogoZonasFecha. Ver DiagnosticoZonasService para el criterio
// (vecinos de zona conocida a ±ventana segundos).
class DiagnosticarZonasFecha extends Command
{
    protected $signature = 'fechas:diagnosticar
        {--tenant=* : Id(s) de tenant. Por defecto: todos, incluidos los archivados}
        {--central : Diagnostica también la base central (systems, system_categories…)}
        {--ventana=3 : Segundos de tolerancia para considerar dos filas de la misma petición (más amplia = más vecinos, pero más coincidencias casuales)}
        {--todas : Muestra también las tablas sin filas}
        {--json= : Ruta donde guardar el reporte completo (incluye la clase de cada fila)}';

    protected $description = 'DIAGNÓSTICO DE SOLO LECTURA: en qué zona (Lima/UTC) está guardado cada created_at, por tenant y tabla, antes de migrar todo a UTC.';

    public function handle(DiagnosticoZonasService $servicio): int
    {
        $ventana = max(1, (int) $this->option('ventana'));
        $ids = $this->option('tenant');
        $tenants = Tenant::query()->when(! empty($ids), fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get();
        if ($tenants->isEmpty() && ! $this->option('central')) {
            $this->error('No se encontró ningún tenant para diagnosticar.');

            return self::FAILURE;
        }

        $reporte = ['generado_en' => now()->toIso8601String(), 'ventana_segundos' => $ventana, 'bases' => []];
        $revisar = 0;

        if ($this->option('central')) {
            $base = $this->soloLectura(DB::connection('central'), fn ($db) => [
                'tablas' => $servicio->diagnosticar($db, true, $ventana),
                'ventas_fecha_distinta' => [],
            ]);
            $revisar += $this->imprimir('CENTRAL', $base);
            $reporte['bases']['central'] = $base;
        }

        foreach ($tenants as $tenant) {
            $base = $tenant->run(fn () => $this->soloLectura(DB::connection(), fn ($db) => [
                'tablas' => $servicio->diagnosticar($db, false, $ventana),
                'ventas_fecha_distinta' => $this->ventasConFechaDistinta($db),
            ]));
            $revisar += $this->imprimir("Tenant {$tenant->id} (" . ($tenant->status ?? 'sin estado') . ')', $base);
            $reporte['bases'][$tenant->id] = $base;
        }

        if ($this->option('json')) {
            file_put_contents($this->option('json'), json_encode($reporte, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Reporte completo guardado en {$this->option('json')}");
        }

        $this->newLine();
        $this->line($revisar === 0
            ? '<info>Todas las tablas coinciden con lo esperado.</info>'
            : "<comment>{$revisar} tabla(s) para revisar (contradicciones con la zona esperada).</comment>");
        $this->warn('Este comando NO modificó ningún dato.');

        return self::SUCCESS;
    }

    /**
     * @template T
     * @param  callable(ConnectionInterface): T  $trabajo
     * @return T
     */
    private function soloLectura(ConnectionInterface $db, callable $trabajo): mixed
    {
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION READ ONLY');

            return $trabajo($db);
        } finally {
            $db->rollBack();
        }
    }

    /**
     * Ventas cuya fecha de emisión impresa (sales.date) no coincide con el día de Perú en que se
     * registraron (sales.created_at está en hora Lima). Solo informativo: incluye fechas puestas a
     * mano a propósito; las de 19:00 en adelante con un día más son el error de zona horaria.
     *
     * @return list<array<string, mixed>>
     */
    private function ventasConFechaDistinta(ConnectionInterface $db): array
    {
        return array_map(static fn ($v): array => (array) $v, $db->select(
            "select id, serie, correlativo, date::text as fecha, created_at::text as registrada, sunat_sent_at::text as enviada,
                    (extract(hour from created_at) >= 19 and date = created_at::date + 1) as nocturna
             from sales where date is not null and date <> created_at::date order by id"
        ));
    }

    /** @param array{tablas: list<array<string, mixed>>, ventas_fecha_distinta: list<array<string, mixed>>} $base */
    private function imprimir(string $titulo, array $base): int
    {
        $this->newLine();
        $this->info("== {$titulo}");
        $filas = [];
        $revisar = 0;
        foreach ($base['tablas'] as $t) {
            if ($t['total'] === 0 && ! $this->option('todas')) {
                continue;
            }
            $contra = count($t['contradicciones']);
            $mixta = $t['esperada'] === CatalogoZonasFecha::MIXTA;
            $estado = $contra > 0 ? 'REVISAR' : ($mixta && $t['LIMA'] > 0 ? 'MIXTA' : 'ok');
            $revisar += $contra > 0 ? 1 : 0;
            $filas[] = [$t['tabla'], $t['esperada'], $t['total'], $t['LIMA'], $t['UTC'], $t['AMBIGUA'], $t['SIN_VECINO'],
                $contra > 0 ? $contra . ' (ids ' . implode(',', array_slice($t['contradicciones'], 0, 5)) . ($contra > 5 ? '…' : '') . ')' : '—',
                $estado];
        }
        $this->table(['Tabla', 'Esperada', 'Filas', 'Lima', 'UTC', 'Ambigua', 'Sin vecino', 'Contradicciones', 'Estado'], $filas);

        $ventas = $base['ventas_fecha_distinta'];
        if ($ventas !== []) {
            $nocturnas = count(array_filter($ventas, static fn ($v) => $v['nocturna']));
            $this->line("Ventas con fecha de emisión distinta al día de registro: " . count($ventas) . " ({$nocturnas} de 19:00 en adelante con un día más)");
            $this->table(['Id', 'Comprobante', 'Fecha impresa', 'Registrada (Lima)', 'Enviada SUNAT', '19-24h'], array_map(static fn ($v) => [
                $v['id'], $v['serie'] . '-' . ($v['correlativo'] ?? '—'), $v['fecha'], $v['registrada'], $v['enviada'] ?? '—', $v['nocturna'] ? 'sí' : '',
            ], array_slice($ventas, 0, 15)));
        }

        return $revisar;
    }
}
