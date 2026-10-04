<?php

declare(strict_types=1);

namespace Tests\Unit\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\CondicionesCredito;
use App\Services\Creditos\Motor\Dto\Cronograma;
use App\Services\Creditos\Motor\Dto\CuotaProgramada;
use App\Services\Creditos\Motor\Dto\CuotaVigente;
use App\Services\Creditos\Motor\Dto\Frecuencia;
use App\Services\Creditos\Motor\Dto\ReglasCalendario;
use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Enums\UnidadTasa;
use App\Services\Creditos\Motor\Excepciones\CondicionesInvalidas;
use App\Services\Creditos\Motor\Tasa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GeneradorCronogramaTest extends TestCase
{
    use ConstruyeCreditos;

    private function generar(
        Frecuencia $frecuencia,
        int $cuotas,
        string $desembolso,
        ReglasCalendario $calendario,
        ?string $primerVencimiento = null,
        int $capital = 100_000,
        string $tasa = '20',
        UnidadTasa $unidad = UnidadTasa::Total,
    ): Cronograma {
        return $this->generador()->generar(new CondicionesCredito(
            montoCapital: $capital,
            tasa: Tasa::desdeTexto($tasa),
            unidadTasa: $unidad,
            frecuencia: $frecuencia,
            numeroCuotas: $cuotas,
            fechaDesembolso: self::f($desembolso),
            fechaPrimerVencimiento: $primerVencimiento === null ? null : self::f($primerVencimiento),
            calendario: $calendario,
        ));
    }

    /** @return list<string> */
    private static function fechas(Cronograma $c): array
    {
        return array_map(static fn (CuotaProgramada $q): string => $q->fechaVencimiento->aTexto(), $c->cuotas);
    }

    // ---- Montos (1.2, 12.1, 12.2) ----

    public function test_ejemplo_12_2_mil_mas_doscientos_en_30_cuotas(): void
    {
        $c = $this->generar(new Frecuencia(FrecuenciaUnidad::Dia), 30, '2026-10-05', self::sinNoLaborables());

        $this->assertSame(20_000, $c->interesTotal);
        $this->assertSame(3_330, $c->cuotas[0]->montoCapital);
        $this->assertSame(670, $c->cuotas[0]->montoInteres);
        $this->assertSame(4_000, $c->cuotas[0]->montoTotal);
        $this->assertSame(3_430, $c->cuotas[29]->montoCapital);
        $this->assertSame(570, $c->cuotas[29]->montoInteres);
        $this->assertSame(4_000, $c->cuotas[29]->montoTotal);
    }

    public function test_salvaguarda_ultima_cuota_negativa_redondea_hacia_abajo(): void
    {
        // Interés 1.50 en 30 cuotas: 0.05 redondearía a 0.10 y la última quedaría en −1.40.
        $c = $this->generar(new Frecuencia(FrecuenciaUnidad::Dia), 30, '2026-10-05', self::sinNoLaborables(), capital: 75_000, tasa: '0.2');

        $this->assertSame(150, $c->interesTotal);
        $this->assertSame(0, $c->cuotas[0]->montoInteres);
        $this->assertSame(150, $c->cuotas[29]->montoInteres);
    }

    /** @return array<string, array{int, string, string, int}> */
    public static function combinacionesDeMontos(): array
    {
        return [
            'redondo' => [500_000, '20', 'total', 10],
            'tasa con decimales' => [123_450, '7.3333', 'total', 7],
            'mensual semanal' => [250_000, '12.5', 'mensual', 13],
            'muchas cuotas' => [100_000, '17', 'total', 97],
            'una cuota' => [99_990, '33.3333', 'total', 1],
        ];
    }

    #[DataProvider('combinacionesDeMontos')]
    public function test_suma_de_cuotas_exacta_y_multiplos_de_diez_centimos(int $capital, string $tasa, string $unidad, int $n): void
    {
        $c = $this->generar(
            new Frecuencia(FrecuenciaUnidad::Semana), $n, '2026-10-05', self::sinNoLaborables(),
            capital: $capital, tasa: $tasa, unidad: UnidadTasa::from($unidad),
        );

        $this->assertSame($capital, array_sum(array_map(static fn (CuotaProgramada $q): int => $q->montoCapital, $c->cuotas)));
        $this->assertSame($c->interesTotal, array_sum(array_map(static fn (CuotaProgramada $q): int => $q->montoInteres, $c->cuotas)));
        $this->assertSame($capital + $c->interesTotal, $c->montoTotal);
        foreach ($c->cuotas as $cuota) {
            $this->assertSame(0, $cuota->montoTotal % 10, "Cuota {$cuota->numero} no es múltiplo de 0.10");
            $this->assertGreaterThanOrEqual(0, $cuota->montoInteres);
        }
    }

    public function test_capital_no_multiplo_del_paso_se_rechaza(): void
    {
        $this->expectException(CondicionesInvalidas::class);
        $this->generar(new Frecuencia(FrecuenciaUnidad::Mes), 3, '2026-10-05', self::sinNoLaborables(), capital: 100_005);
    }

    public function test_primer_vencimiento_no_posterior_al_desembolso_se_rechaza(): void
    {
        $this->expectException(CondicionesInvalidas::class);
        $this->generar(new Frecuencia(FrecuenciaUnidad::Mes), 3, '2026-10-05', self::sinNoLaborables(), '2026-10-05');
    }

    public function test_quincenal_sin_dos_dias_validos_se_rechaza(): void
    {
        $this->expectException(CondicionesInvalidas::class);
        $this->generar(new Frecuencia(FrecuenciaUnidad::Quincena, 1, [15]), 3, '2026-10-05', self::sinNoLaborables());
    }

    // ---- Fechas (1.3, 1.4) ----

    /** @return array<string, array{list<int>, string}> */
    public static function diarioConDiasNoLaborables(): array
    {
        return [
            'sin domingos' => [[7], '2026-11-02'],
            'sin sábados ni domingos' => [[6, 7], '2026-11-06'],
            'todos laborables' => [[], '2026-10-29'],
        ];
    }

    #[DataProvider('diarioConDiasNoLaborables')]
    public function test_diario_24_el_dia_no_laborable_no_genera_cuota(array $noLaborables, string $ultima): void
    {
        // Desembolso lunes 05/10/2026.
        $c = $this->generar(new Frecuencia(FrecuenciaUnidad::Dia), 24, '2026-10-05', new ReglasCalendario($noLaborables));

        $this->assertCount(24, $c->cuotas);
        $this->assertSame('2026-10-06', $c->cuotas[0]->fechaVencimiento->aTexto());
        $this->assertSame($ultima, $c->cuotas[23]->fechaVencimiento->aTexto());
        foreach ($c->cuotas as $cuota) {
            $this->assertNotContains($cuota->fechaVencimiento->diaIso(), $noLaborables);
        }
    }

    /** @return array<string, array{ReglaNoLaborable, bool, string}> */
    public static function feriadoEnMedio(): array
    {
        return [
            'siguiente' => [ReglaNoLaborable::Siguiente, true, '2026-07-02'],
            'anterior' => [ReglaNoLaborable::Anterior, true, '2026-06-30'],
            'mantener' => [ReglaNoLaborable::Mantener, true, '2026-07-01'],
            'feriados desactivados' => [ReglaNoLaborable::Siguiente, false, '2026-07-01'],
        ];
    }

    #[DataProvider('feriadoEnMedio')]
    public function test_feriado_en_un_vencimiento_mensual(ReglaNoLaborable $regla, bool $saltar, string $esperada): void
    {
        $calendario = new ReglasCalendario([], $saltar, [self::f('2026-07-01')], $regla);
        $c = $this->generar(new Frecuencia(FrecuenciaUnidad::Mes), 3, '2026-06-01', $calendario);

        $this->assertSame([$esperada, '2026-08-01', '2026-09-01'], self::fechas($c));
    }

    public function test_anterior_no_retrocede_antes_del_desembolso_ni_de_la_cuota_previa(): void
    {
        // Desembolso sábado 10/10, primer vencimiento domingo 11/10: retroceder caería en el desembolso.
        $calendario = new ReglasCalendario([7], regla: ReglaNoLaborable::Anterior);
        $c = $this->generar(new Frecuencia(FrecuenciaUnidad::Semana), 2, '2026-10-10', $calendario, '2026-10-11');

        $this->assertSame('2026-10-12', $c->cuotas[0]->fechaVencimiento->aTexto());
        $this->assertTrue($c->cuotas[0]->fechaForzadaASiguiente);
        $this->assertSame('2026-10-17', $c->cuotas[1]->fechaVencimiento->aTexto());   // domingo 18 → sábado 17
        $this->assertFalse($c->cuotas[1]->fechaForzadaASiguiente);
    }

    public function test_semanal_10(): void
    {
        $c = $this->generar(new Frecuencia(FrecuenciaUnidad::Semana), 10, '2026-10-05', new ReglasCalendario([7]));

        $this->assertSame('2026-10-12', $c->cuotas[0]->fechaVencimiento->aTexto());
        $this->assertSame('2026-12-14', $c->cuotas[9]->fechaVencimiento->aTexto());
        $this->assertSame('2026-10-05', $c->cuotas[0]->fechaInicioPeriodo->aTexto());
        $this->assertSame('2026-10-12', $c->cuotas[1]->fechaInicioPeriodo->aTexto());
    }

    public function test_quincenal_corrido_cada_15_dias(): void
    {
        $c = $this->generar(new Frecuencia(FrecuenciaUnidad::Dia, 15), 4, '2026-01-01', self::sinNoLaborables());

        $this->assertSame(['2026-01-16', '2026-01-31', '2026-02-15', '2026-03-02'], self::fechas($c));
    }

    public function test_quincenal_dia_15_y_ultimo(): void
    {
        $c = $this->generar(
            new Frecuencia(FrecuenciaUnidad::Quincena, 1, [15, Frecuencia::ULTIMO_DIA]), 6, '2026-01-05', self::sinNoLaborables(),
        );

        $this->assertSame(
            ['2026-01-15', '2026-01-31', '2026-02-15', '2026-02-28', '2026-03-15', '2026-03-31'],
            self::fechas($c),
        );
    }

    public function test_mensual_12(): void
    {
        $c = $this->generar(new Frecuencia(FrecuenciaUnidad::Mes), 12, '2026-01-10', self::sinNoLaborables(), tasa: '5', unidad: UnidadTasa::Mensual);

        $this->assertSame('2026-02-10', $c->cuotas[0]->fechaVencimiento->aTexto());
        $this->assertSame('2027-01-10', $c->cuotas[11]->fechaVencimiento->aTexto());
        $this->assertSame(60_000, $c->interesTotal);
        $this->assertSame(5_000, $c->cuotas[0]->montoInteres);
    }

    public function test_anual_5_desde_29_de_febrero(): void
    {
        $c = $this->generar(new Frecuencia(FrecuenciaUnidad::Anio), 5, '2024-02-29', self::sinNoLaborables());

        $this->assertSame(['2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29', '2029-02-28'], self::fechas($c));
    }

    public function test_mensual_desde_31_de_enero_ajusta_al_ultimo_dia_sin_arrastrar(): void
    {
        $c = $this->generar(new Frecuencia(FrecuenciaUnidad::Mes), 3, '2026-01-10', self::sinNoLaborables(), '2026-01-31');

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], self::fechas($c));
    }

    public function test_sugerir_primer_vencimiento_aplica_el_calendario(): void
    {
        // Lunes 05/10 + 6 días = domingo 11/10 → lunes 12/10.
        $sugerida = $this->generador()->sugerirPrimerVencimiento(
            self::f('2026-10-05'), new Frecuencia(FrecuenciaUnidad::Dia, 6), new ReglasCalendario([7]),
        );

        $this->assertSame('2026-10-12', $sugerida->aTexto());
    }

    public function test_corregir_credito_regenera_el_cronograma_con_las_nuevas_condiciones(): void
    {
        // 1.8: la versión 2 es un cronograma nuevo generado desde cero (numerar la versión es de Fase 3).
        $v1 = $this->generar(new Frecuencia(FrecuenciaUnidad::Mes), 3, '2026-01-10', self::sinNoLaborables());
        $v2 = $this->generar(new Frecuencia(FrecuenciaUnidad::Mes), 4, '2026-01-10', self::sinNoLaborables(), capital: 120_000);

        $this->assertCount(3, $v1->cuotas);
        $this->assertCount(4, $v2->cuotas);
        $this->assertSame(144_000, $v2->montoTotal);
    }

    // ---- Reprogramación de fechas (1.18) ----

    /** @return list<CuotaVigente> cuotas semanales: c1 13/10 … c5 10/11, c6 17/11, c7 24/11 */
    private function cuotasSemanales(): array
    {
        $c = $this->generar(new Frecuencia(FrecuenciaUnidad::Semana), 7, '2026-10-06', self::sinNoLaborables(), '2026-10-13');

        return array_map(CuotaVigente::desdeProgramada(...), $c->cuotas);
    }

    public function test_reprogramar_10_dias_desde_la_cuota_5(): void
    {
        $nuevas = $this->generador()->desplazarFechas($this->cuotasSemanales(), 5, 10, self::f('2026-11-01'));

        $this->assertSame('2026-11-03', $nuevas[3]->fechaVencimiento->aTexto());
        $this->assertSame('2026-11-20', $nuevas[4]->fechaVencimiento->aTexto());
        $this->assertSame('2026-11-27', $nuevas[5]->fechaVencimiento->aTexto());
        $this->assertSame('2026-12-04', $nuevas[6]->fechaVencimiento->aTexto());
        // Montos y período original intactos (divisor de mora, 1.18).
        $this->assertSame('2026-11-10', $nuevas[4]->fechaVencimientoOriginal->aTexto());
        $this->assertSame($this->cuotasSemanales()[4]->montoInteres, $nuevas[4]->montoInteres);
    }

    public function test_reprogramar_no_permite_cuotas_pagadas(): void
    {
        $this->expectException(CondicionesInvalidas::class);
        $this->generador()->desplazarFechas($this->cuotasSemanales(), 5, 10, self::f('2026-11-01'), [5]);
    }

    public function test_reprogramar_no_permite_fechas_antes_de_hoy(): void
    {
        $this->expectException(CondicionesInvalidas::class);
        $this->generador()->desplazarFechas($this->cuotasSemanales(), 5, 10, self::f('2026-11-25'));
    }

    public function test_reprogramar_una_por_una_exige_orden_ascendente(): void
    {
        $this->expectException(CondicionesInvalidas::class);
        $this->generador()->validarFechasReprogramadas(
            $this->cuotasSemanales(), [5 => self::f('2026-11-18')], self::f('2026-11-01'),
        );
    }
}
