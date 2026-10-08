<?php

namespace Tests\Unit;

use App\Services\HoraPeru;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

// 07-oct-2026 — el recibo de créditos decía "Generado el 07/10/2026 16:08" impreso a
// las 11:08 en Perú: las plantillas usaban now(), que sale en UTC (config/app.php).
class HoraPeruTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_devuelve_la_hora_de_peru_y_no_la_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 16:08:00', 'UTC'));

        $this->assertSame('07/10/2026 11:08', HoraPeru::ahora()->format('d/m/Y H:i'));
    }

    public function test_de_noche_sigue_siendo_el_mismo_dia_en_peru(): void
    {
        // 23:30 en Lima del 31-oct = 04:30 UTC del 01-nov: el período ("my") no debe saltar de mes.
        Carbon::setTestNow(Carbon::parse('2026-11-01 04:30:00', 'UTC'));

        $this->assertSame('2026-10-31', HoraPeru::ahora()->format('Y-m-d'));
        $this->assertSame('1026', HoraPeru::ahora()->format('my'));
    }

    // 08-oct-2026 — apertura de caja y created_at de créditos se guardan en UTC y los PDFs los
    // imprimían tal cual (5 horas adelantados).
    public function test_de_utc_convierte_a_hora_de_peru(): void
    {
        $this->assertSame('08/10/2026 16:58', HoraPeru::deUtc('2026-10-08 21:58:37')->format('d/m/Y H:i'));
        $this->assertSame('2026-10-08', HoraPeru::deUtc(Carbon::parse('2026-10-09 03:00:00', 'UTC'))->format('Y-m-d'));
        $this->assertNull(HoraPeru::deUtc(null));
    }

    public function test_de_utc_no_depende_de_la_zona_por_defecto_de_php(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('America/Lima');   // como hacen los mutadores de algunos modelos
        try {
            $this->assertSame('16:58', HoraPeru::deUtc(new \DateTimeImmutable('2026-10-08 21:58:37'))->format('H:i'));
        } finally {
            date_default_timezone_set($original);
        }
    }
}
