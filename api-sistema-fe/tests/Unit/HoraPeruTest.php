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
}
