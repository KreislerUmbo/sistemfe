<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

// Regla única de fechas (08-oct-2026): la app corre en UTC. Una fecha de negocio ("hoy": emisión,
// pago, vigencia, filtro) sale de App\Services\HoraPeru (o Creditos\Reloj), nunca de now()/today(),
// que de 19:00 a 24:00 de Perú ya están en el día siguiente. Este test falla si vuelve a aparecer.
class ReglaFechasNegocioTest extends TestCase
{
    private const PROHIBIDOS = [
        '/\btoday\(\)/' => 'today()',
        '/Carbon(Immutable)?::today\(\)/' => 'Carbon::today()',
        '/now\(\)->toDateString\(\)/' => 'now()->toDateString()',
        '/now\(\)->format\(\s*[\'"]Y-m(-d)?[\'"]\s*\)/' => "now()->format('Y-m[-d]')",
        '/new\s+\\\\?DateTime\(\s*\)/' => 'new DateTime()',
    ];

    // Excepciones justificadas: comparan contra columnas que se guardan en UTC.
    private const PERMITIDOS = [
        'app/Services/TenantBackupService.php',   // created_at de respaldos (UTC), corre a las 03:00 de Perú
    ];

    public function test_ninguna_fecha_de_negocio_usa_now_o_today(): void
    {
        $base = dirname(__DIR__, 2);
        $hallazgos = [];
        $archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/app'));
        foreach ($archivos as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }
            $relativo = str_replace('\\', '/', substr($archivo->getPathname(), strlen($base) + 1));
            if (in_array($relativo, self::PERMITIDOS, true)) {
                continue;
            }
            foreach (file($archivo->getPathname()) as $n => $linea) {
                if (preg_match('/^\s*(\/\/|\*|#)/', $linea)) {
                    continue;   // comentarios
                }
                foreach (self::PROHIBIDOS as $patron => $nombre) {
                    if (preg_match($patron, $linea)) {
                        $hallazgos[] = "{$relativo}:" . ($n + 1) . " usa {$nombre}";
                    }
                }
            }
        }

        $this->assertSame([], $hallazgos, "Usar HoraPeru::hoyTexto()/hoy()/ahora() para fechas de negocio:\n" . implode("\n", $hallazgos));
    }
}
