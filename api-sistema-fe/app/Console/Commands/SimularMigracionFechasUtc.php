<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ZonaHoraria\MigracionFechasUtcService;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

// Homogenización de fechas, F3. SOLO LECTURA (transacción READ ONLY que se revierte): muestra lo
// que harán las migraciones *_migrar_fechas_lima_a_utc en cada base. Los datos NO se cambian
// desde acá: lo hace `php artisan migrate` / `tenants:migrate-verticales` (deploy.sh), en el
// mismo despliegue que el código sin mutadores.
class SimularMigracionFechasUtc extends Command
{
    protected $signature = 'fechas:migrar-utc
        {--simular : Obligatorio: solo muestra el plan, no modifica nada}
        {--tenant=* : Id(s) de tenant. Por defecto: todos}
        {--central : Incluye la base central}
        {--json= : Ruta donde guardar el plan completo (con todos los ids)}';

    protected $description = 'SOLO LECTURA: plan de la migración de fechas Lima → UTC (+5 h) por base, tabla y columna.';

    public function handle(MigracionFechasUtcService $servicio): int
    {
        if (! $this->option('simular')) {
            $this->error('Este comando solo simula (--simular). Los datos los migra `php artisan migrate` / deploy.sh, junto con el código nuevo.');

            return self::FAILURE;
        }

        $ids = $this->option('tenant');
        $planes = [];
        if ($this->option('central')) {
            $planes['central'] = $this->soloLectura(DB::connection('central'), fn ($db) => $servicio->plan($db, true));
        }
        foreach (Tenant::query()->when(! empty($ids), fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get() as $tenant) {
            $planes[$tenant->id] = $tenant->run(fn () => $this->soloLectura(DB::connection(), fn ($db) => $servicio->plan($db)));
        }

        $total = 0;
        foreach ($planes as $base => $plan) {
            $this->newLine();
            $this->info("== {$base}");
            if ($plan === []) {
                $this->line('   Nada que migrar.');

                continue;
            }
            $this->table(['Tabla', 'Columnas (+5 h)', 'Filas', 'Ids'], array_map(static fn (array $p): array => [
                $p['tabla'], implode(', ', $p['columnas']), count($p['ids']),
                implode(',', array_slice($p['ids'], 0, 8)) . (count($p['ids']) > 8 ? '…' : ''),
            ], $plan));
            $total += array_sum(array_map(static fn (array $p): int => count($p['ids']), $plan));
        }

        if ($this->option('json')) {
            file_put_contents($this->option('json'), json_encode($planes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Plan completo guardado en {$this->option('json')}");
        }

        $this->newLine();
        $this->line("Filas a corregir en total: {$total}.");
        $this->warn('Este comando NO modificó ningún dato.');

        return self::SUCCESS;
    }

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
}
