<?php

namespace Tests\Unit;

use App\Services\HoraPeru;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// F0 de la homogenización de fechas (08-oct-2026): "hoy" de negocio y límites de un día de Perú
// en UTC. Los casos van de 19:00 a 24:00 de Perú, donde UTC ya está en el día siguiente.
class HoraPeruDiaTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function nochesDePeru(): array
    {
        return [
            '19:00' => ['2026-10-09 00:00:00', '2026-10-08'],
            '23:59' => ['2026-10-09 04:59:59', '2026-10-08'],
            '00:30 del día siguiente' => ['2026-10-09 05:30:00', '2026-10-09'],
            'fin de mes 23:30' => ['2026-11-01 04:30:00', '2026-10-31'],
        ];
    }

    #[DataProvider('nochesDePeru')]
    public function test_hoy_es_el_dia_de_peru(string $utc, string $esperado): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

        $this->assertSame($esperado, HoraPeru::hoyTexto());
        $this->assertSame($esperado . ' 00:00:00', HoraPeru::hoy()->format('Y-m-d H:i:s'));
    }

    public function test_hoy_se_compara_bien_contra_una_columna_date_del_mismo_dia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 02:00:00', 'UTC'));   // 21:00 del 08-oct en Perú

        $vencimiento = Carbon::parse('2026-10-08');
        $this->assertFalse($vencimiento->lt(HoraPeru::hoy()));
    }

    public function test_limites_utc_de_un_dia_de_peru(): void
    {
        $this->assertSame('2026-10-08 05:00:00', HoraPeru::inicioDiaUtc('2026-10-08'));
        $this->assertSame('2026-10-09 05:00:00', HoraPeru::finDiaUtc('2026-10-08'));
        $this->assertSame('2026-11-01 05:00:00', HoraPeru::finDiaUtc('2026-10-31'));
    }
}
