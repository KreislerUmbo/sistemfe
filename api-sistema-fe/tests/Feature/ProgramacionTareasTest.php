<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tareas programadas (routes/console.php) en hora de Lima. La app corre en UTC: sin
 * ->timezone(), "02:00" corría a las 21:00 de Lima (hallazgo 07-oct-2026 en producción).
 */
final class ProgramacionTareasTest extends TestCase
{
    /** comando => [expresión cron, zona] */
    private const ESPERADO = [
        'tenants:generate-monthly-invoices' => ['0 0 * * *', 'America/Lima'],
        'tenants:check-overdue-payments' => ['0 0 * * *', 'America/Lima'],
        'tenants:run-automatic-backups' => ['0 3 * * *', 'America/Lima'],
        'tipo-cambio:sincronizar-sunat' => ['0 5 * * *', 'America/Lima'],
        'creditos:escalamiento' => ['30 2 * * *', 'America/Lima'],
        'creditos:foto-cartera' => ['50 23 * * *', 'America/Lima'],
    ];

    public function test_cada_tarea_corre_a_su_hora_de_lima(): void
    {
        Artisan::call('schedule:list');   // carga routes/console.php en el Schedule

        $eventos = collect(app(Schedule::class)->events());
        foreach (self::ESPERADO as $comando => [$cron, $zona]) {
            $evento = $eventos->first(fn (Event $e): bool => str_contains((string) $e->command, $comando));
            $this->assertNotNull($evento, "Falta programar {$comando}.");
            $this->assertSame($cron, $evento->expression, "Hora de {$comando}.");
            $this->assertSame($zona, (string) $evento->timezone, "Zona de {$comando}.");
        }
    }

    public function test_ninguna_tarea_queda_en_utc_por_omision(): void
    {
        Artisan::call('schedule:list');

        $sinZona = collect(app(Schedule::class)->events())
            ->filter(fn (Event $e): bool => (string) $e->timezone !== 'America/Lima')
            ->map(fn (Event $e): string => (string) $e->command)
            ->values()->all();

        $this->assertSame([], $sinZona, 'Tareas sin ->timezone(\'America/Lima\'): corren en UTC.');
    }
}
