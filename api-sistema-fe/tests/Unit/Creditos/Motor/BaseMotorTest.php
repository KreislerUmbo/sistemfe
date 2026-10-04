<?php

declare(strict_types=1);

namespace Tests\Unit\Creditos\Motor;

use App\Services\Creditos\Motor\CalendarioLaborable;
use App\Services\Creditos\Motor\Dto\ReglasCalendario;
use App\Services\Creditos\Motor\Enums\ModoRedondeo;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Excepciones\CondicionesInvalidas;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Motor\Redondeo;
use App\Services\Creditos\Motor\Tasa;
use PHPUnit\Framework\TestCase;

/** Redondeo, Fecha, Tasa y CalendarioLaborable. */
class BaseMotorTest extends TestCase
{
    use ConstruyeCreditos;

    public function test_redondeo_medio_hacia_arriba_a_diez_centimos(): void
    {
        $this->assertSame(3330, Redondeo::dividir(100_000, 30, Redondeo::PASO_DIEZ_CENTIMOS)); // 33.333 → 33.30
        $this->assertSame(670, Redondeo::dividir(20_000, 30, Redondeo::PASO_DIEZ_CENTIMOS));   // 6.666 → 6.70
        $this->assertSame(10, Redondeo::dividir(5, 1, Redondeo::PASO_DIEZ_CENTIMOS));          // 0.05 → 0.10
        $this->assertSame(0, Redondeo::dividir(5, 1, Redondeo::PASO_DIEZ_CENTIMOS, ModoRedondeo::Abajo));
        $this->assertSame(333, Redondeo::dividir(1000, 3));
    }

    public function test_multiplicar_detecta_desborde(): void
    {
        $this->expectException(\OverflowException::class);
        Redondeo::multiplicar(PHP_INT_MAX, 2);
    }

    public function test_fecha_aritmetica_y_dia_iso(): void
    {
        $this->assertSame(1, self::f('2026-10-05')->diaIso());   // lunes
        $this->assertSame(6, self::f('2026-10-10')->diaIso());   // sábado
        $this->assertSame(7, self::f('2026-10-11')->diaIso());   // domingo
        $this->assertSame('2026-03-02', self::f('2026-01-31')->sumarDias(30)->aTexto());
        $this->assertSame(-5, self::f('2026-10-15')->diasHasta(self::f('2026-10-10')));
        $this->assertSame('2024-02-29', self::f('2023-12-31')->sumarDias(60)->aTexto());
    }

    public function test_sumar_meses_anclado_ajusta_fin_de_mes(): void
    {
        $base = self::f('2026-01-31');
        $this->assertSame('2026-02-28', $base->sumarMesesAnclado(1, 31)->aTexto());
        $this->assertSame('2026-03-31', $base->sumarMesesAnclado(2, 31)->aTexto());
        $this->assertSame('2027-01-31', $base->sumarMesesAnclado(12, 31)->aTexto());
    }

    public function test_fecha_invalida_se_rechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Fecha::desdeTexto('2026-02-30');
    }

    public function test_tasa_desde_texto_sin_float(): void
    {
        $this->assertSame(200_000, Tasa::desdeTexto('20.0000')->diezmilesimas);
        $this->assertSame(200_000, Tasa::desdeTexto('20')->diezmilesimas);
        $this->assertSame(33_333, Tasa::desdeTexto('3.3333')->diezmilesimas);
        $this->assertSame(205_000, Tasa::desdeTexto('20.5')->diezmilesimas);
        $this->assertSame('3.3333', Tasa::desdeTexto('3.3333')->aTexto());
    }

    public function test_calendario_domingo_y_feriado(): void
    {
        $cal = new CalendarioLaborable(new ReglasCalendario([7], true, [self::f('2026-10-08')]));

        $this->assertFalse($cal->esLaborable(self::f('2026-10-11')));   // domingo
        $this->assertFalse($cal->esLaborable(self::f('2026-10-08')));   // feriado
        $this->assertTrue($cal->esLaborable(self::f('2026-10-10')));    // sábado
        $this->assertSame('2026-10-12', $cal->siguienteLaborable(self::f('2026-10-11'))->aTexto());
    }

    public function test_feriado_ignorado_si_no_se_saltan_feriados(): void
    {
        $cal = new CalendarioLaborable(new ReglasCalendario([7], false, [self::f('2026-10-08')]));

        $this->assertTrue($cal->esLaborable(self::f('2026-10-08')));
    }

    public function test_dias_entre_sabado_y_lunes_segun_bandera(): void
    {
        $cal = new CalendarioLaborable(new ReglasCalendario([7]));

        $this->assertSame(2, $cal->diasEntre(self::f('2026-10-10'), self::f('2026-10-12'), true));
        $this->assertSame(1, $cal->diasEntre(self::f('2026-10-10'), self::f('2026-10-12'), false));
    }

    public function test_anterior_imposible_se_fuerza_a_siguiente(): void
    {
        $cal = new CalendarioLaborable(new ReglasCalendario([7], regla: ReglaNoLaborable::Anterior));

        // Domingo 11/10 con límite sábado 10/10: retroceder caería en el límite → lunes 12/10 (12.3).
        $ajustada = $cal->ajustarDetallado(self::f('2026-10-11'), self::f('2026-10-10'));
        $this->assertSame('2026-10-12', $ajustada->fecha->aTexto());
        $this->assertTrue($ajustada->forzadaASiguiente);

        $normal = $cal->ajustarDetallado(self::f('2026-10-11'), self::f('2026-10-05'));
        $this->assertSame('2026-10-10', $normal->fecha->aTexto());
        $this->assertFalse($normal->forzadaASiguiente);
    }

    public function test_semana_sin_dias_laborables_se_rechaza(): void
    {
        $this->expectException(CondicionesInvalidas::class);
        new CalendarioLaborable(new ReglasCalendario([1, 2, 3, 4, 5, 6, 7]));
    }
}
