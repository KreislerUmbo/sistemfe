<?php

declare(strict_types=1);

namespace Tests\Unit\Creditos\Motor;

use App\Services\Creditos\Motor\CalendarioLaborable;
use App\Services\Creditos\Motor\Dto\Abono;
use App\Services\Creditos\Motor\Dto\CuotaVigente;
use App\Services\Creditos\Motor\Dto\EstadoCredito;
use App\Services\Creditos\Motor\Dto\MoraCongelada;
use App\Services\Creditos\Motor\Dto\MoraCuota;
use App\Services\Creditos\Motor\Dto\PeriodoCastigo;
use App\Services\Creditos\Motor\Dto\ReglasCalendario;
use App\Services\Creditos\Motor\Dto\ReglasMora;
use App\Services\Creditos\Motor\Enums\ConceptoAplicacion;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Tasa;
use PHPUnit\Framework\TestCase;

/**
 * Cuota base del ejemplo 1.6: 1,200 (1,000 + 200), período 10/09 → 10/10/2026 (30 días),
 * mora diaria = 1,200 / 30 = 40. El 10/10/2026 es sábado.
 */
class CalculadoraMoraTest extends TestCase
{
    use ConstruyeCreditos;

    private function cuota(int $cargo = 0, int $congelada = 0, int $condonada = 0, string $vencimiento = '2026-10-10'): CuotaVigente
    {
        return new CuotaVigente(
            1, self::f('2026-09-10'), self::f($vencimiento), self::f('2026-10-10'),
            100_000, 20_000, $cargo, $congelada, $condonada,
        );
    }

    /** @return list<Abono> */
    private static function pagoCompleto(string $fecha): array
    {
        return [
            new Abono(self::f($fecha), ConceptoAplicacion::Interes, 20_000),
            new Abono(self::f($fecha), ConceptoAplicacion::Capital, 100_000),
        ];
    }

    /** @param list<Abono> $abonos */
    private function calcular(
        array $abonos,
        string $fecha,
        ReglasMora $reglas = new ReglasMora(),
        ?CuotaVigente $cuota = null,
        ReglasCalendario $calendario = new ReglasCalendario([]),
    ): MoraCuota {
        return $this->mora()->deCuota(
            $cuota ?? $this->cuota(), $abonos, self::f($fecha), $reglas, new CalendarioLaborable($calendario),
        );
    }

    public function test_ejemplo_1_6_paga_5_dias_tarde(): void
    {
        $mora = $this->calcular(self::pagoCompleto('2026-10-15'), '2026-10-15');

        $this->assertSame(20_000, $mora->moraGenerada);   // 40 × 5
        $this->assertSame(20_000, $mora->moraPendiente);
        $this->assertSame(0, $mora->diasAtraso);            // ya pagada
    }

    public function test_mora_se_detiene_al_pagar_la_cuota(): void
    {
        $mora = $this->calcular(self::pagoCompleto('2026-10-15'), '2026-11-30');

        $this->assertSame(20_000, $mora->moraGenerada);
    }

    public function test_cuota_impaga_sigue_generando_mora(): void
    {
        $mora = $this->calcular([], '2026-10-20');

        $this->assertSame(40_000, $mora->moraGenerada);   // 40 × 10
        $this->assertSame(10, $mora->diasAtraso);
    }

    public function test_gracia_3_dias_pago_dia_13_sin_mora_y_dia_15_con_2_dias(): void
    {
        $reglas = new ReglasMora(diasGracia: 3);

        $this->assertSame(0, $this->calcular(self::pagoCompleto('2026-10-13'), '2026-10-13', $reglas)->moraGenerada);
        $this->assertSame(8_000, $this->calcular(self::pagoCompleto('2026-10-15'), '2026-10-15', $reglas)->moraGenerada);
    }

    public function test_mora_con_abono_parcial_por_tramos(): void
    {
        // 11 y 12 sobre 1,200 (2,400) + 13 a 15 sobre 600 (1,800) = 4,200 / 30 = 140.
        $abonos = [
            new Abono(self::f('2026-10-12'), ConceptoAplicacion::Interes, 20_000),
            new Abono(self::f('2026-10-12'), ConceptoAplicacion::Capital, 40_000),
        ];

        $this->assertSame(14_000, $this->calcular($abonos, '2026-10-15')->moraGenerada);
    }

    public function test_el_dia_del_abono_aun_corre_sobre_el_saldo_anterior(): void
    {
        // Abono de 600 el 11/10: el 11 cuenta sobre 1,200; el 12 y 13 sobre 600 → 2,400 / 30 = 80.
        $abonos = [
            new Abono(self::f('2026-10-11'), ConceptoAplicacion::Interes, 20_000),
            new Abono(self::f('2026-10-11'), ConceptoAplicacion::Capital, 40_000),
        ];

        $this->assertSame(8_000, $this->calcular($abonos, '2026-10-13')->moraGenerada);
    }

    public function test_mora_sabado_a_lunes_segun_mora_cuenta_no_laborables(): void
    {
        $sinDomingo = new ReglasCalendario([7]);

        $calendario = $this->calcular(self::pagoCompleto('2026-10-12'), '2026-10-12', new ReglasMora(cuentaNoLaborables: true), null, $sinDomingo);
        $laborables = $this->calcular(self::pagoCompleto('2026-10-12'), '2026-10-12', new ReglasMora(cuentaNoLaborables: false), null, $sinDomingo);

        $this->assertSame(8_000, $calendario->moraGenerada);   // domingo + lunes
        $this->assertSame(4_000, $laborables->moraGenerada);   // solo lunes; divisor sigue siendo 30 (12.4)
    }

    public function test_mora_no_corre_sobre_el_cargo(): void
    {
        // Paga capital e interés a tiempo pero no el cargo de 20: no hay mora.
        $mora = $this->calcular(self::pagoCompleto('2026-10-10'), '2026-10-30', cuota: $this->cuota(cargo: 2_000));

        $this->assertSame(0, $mora->moraGenerada);
    }

    public function test_tope_por_porcentaje_de_cuota(): void
    {
        $mora = $this->calcular([], '2026-10-30', new ReglasMora(topeTipo: TopeMoraTipo::PorcentajeCuota, topeValor: 10));

        $this->assertSame(12_000, $mora->moraGenerada);   // 800 topado a 10% de 1,200
        $this->assertTrue($mora->topeAlcanzado);
    }

    public function test_tope_por_porcentaje_de_cuota_incluye_mora_congelada(): void
    {
        $reglas = new ReglasMora(topeTipo: TopeMoraTipo::PorcentajeCuota, topeValor: 10);
        $mora = $this->calcular([], '2026-10-30', $reglas, $this->cuota(congelada: 5_000));

        $this->assertSame(7_000, $mora->moraGenerada);
        $this->assertSame(12_000, $mora->moraPendiente);
    }

    public function test_tope_por_dias_maximos(): void
    {
        $mora = $this->calcular([], '2026-10-20', new ReglasMora(topeTipo: TopeMoraTipo::DiasMaximos, topeValor: 3));

        $this->assertSame(12_000, $mora->moraGenerada);   // 40 × 3
        $this->assertTrue($mora->topeAlcanzado);
        $this->assertSame(10, $mora->diasAtraso);
    }

    public function test_credito_sin_mora_no_genera_mora_pero_cuenta_el_atraso(): void
    {
        $mora = $this->calcular([], '2026-12-09', new ReglasMora(topeTipo: TopeMoraTipo::SinTope, cobraMora: false));

        $this->assertSame(0, $mora->moraGenerada);
        $this->assertSame(0, $mora->moraPendiente);
        $this->assertFalse($mora->topeAlcanzado);
        $this->assertSame(60, $mora->diasAtraso);   // escalamiento y bloqueo siguen viendo el atraso
    }

    public function test_sin_tope(): void
    {
        $mora = $this->calcular([], '2026-12-09', new ReglasMora(topeTipo: TopeMoraTipo::SinTope));

        $this->assertSame(240_000, $mora->moraGenerada);   // 60 días × 40
        $this->assertFalse($mora->topeAlcanzado);
    }

    public function test_tope_por_porcentaje_de_capital_se_reparte_de_la_mas_antigua(): void
    {
        // 1,000 en 2 cuotas de 600 cada 30 días; tope 5% del capital = 50.
        $cronograma = $this->cronogramaCada30Dias(100_000, '20', 2, '2026-09-10');
        $estado = $this->estado($cronograma, 100_000, '10', new ReglasMora(topeTipo: TopeMoraTipo::PorcentajeCapital, topeValor: 5));

        $moras = $this->mora()->deCredito($estado, [], self::f('2026-11-19'));

        $this->assertSame(5_000, $moras[0]->moraGenerada);
        $this->assertTrue($moras[0]->topeAlcanzado);
        $this->assertSame(0, $moras[1]->moraGenerada);
        $this->assertTrue($moras[1]->topeAlcanzado);
    }

    public function test_castigo_congela_la_mora_pero_no_los_dias_de_atraso(): void
    {
        $mora = $this->calcular([], '2026-10-30', new ReglasMora(periodosCastigo: [new PeriodoCastigo(self::f('2026-10-15'))]));

        $this->assertSame(20_000, $mora->moraGenerada);   // solo hasta el castigo
        $this->assertSame(20, $mora->diasAtraso);
    }

    public function test_castigar_y_revertir_no_genera_mora_del_periodo_castigado(): void
    {
        // Castigo el 15/10, revertido el 25/10: corre del 11 al 15 y del 26 al 30 (10 días × 40).
        $reglas = new ReglasMora(periodosCastigo: [new PeriodoCastigo(self::f('2026-10-15'), self::f('2026-10-25'))]);

        $mora = $this->calcular([], '2026-10-30', $reglas);

        $this->assertSame(40_000, $mora->moraGenerada);
        $this->assertSame(80_000, $this->calcular([], '2026-10-30')->moraGenerada);   // sin castigo: 20 días
    }

    public function test_varios_periodos_de_castigo_y_abono_dentro_del_castigo(): void
    {
        // Castigos 12/10→14/10 y 20/10→vigente; abono de 600 el 13/10 (dentro del primero).
        // Días con mora: 11 y 12 sobre 1,200 (2,400) + 15 a 20 sobre 600 (3,600) = 6,000 / 30 = 200.
        $reglas = new ReglasMora(periodosCastigo: [
            new PeriodoCastigo(self::f('2026-10-12'), self::f('2026-10-14')),
            new PeriodoCastigo(self::f('2026-10-20')),
        ]);
        $abonos = [
            new Abono(self::f('2026-10-13'), ConceptoAplicacion::Interes, 20_000),
            new Abono(self::f('2026-10-13'), ConceptoAplicacion::Capital, 40_000),
        ];

        $this->assertSame(20_000, $this->calcular($abonos, '2026-10-30', $reglas)->moraGenerada);
    }

    public function test_reprogramar_manteniendo_mora_congelada(): void
    {
        // Reprogramada el 20/10 al 10/11 con 10 días de mora (400) congelados.
        $cuota = $this->cuota(congelada: 40_000, vencimiento: '2026-11-10');

        $antes = $this->calcular([], '2026-11-05', cuota: $cuota);
        $this->assertSame(0, $antes->moraGenerada);
        $this->assertSame(40_000, $antes->moraPendiente);

        // 3 días tras la nueva fecha, divisor del período original (30): 1,200 × 3 / 30 = 120.
        $despues = $this->calcular([], '2026-11-13', cuota: $cuota);
        $this->assertSame(12_000, $despues->moraGenerada);
        $this->assertSame(52_000, $despues->moraPendiente);
    }

    public function test_mora_congelada_con_fecha_solo_existe_desde_su_reprogramacion(): void
    {
        // Reprogramada el 20/10 al 10/11 congelando 400: antes del 20/10 esa deuda no existía así.
        $cuota = new CuotaVigente(
            1, self::f('2026-09-10'), self::f('2026-11-10'), self::f('2026-10-10'), 100_000, 20_000,
            morasCongeladas: [new MoraCongelada(self::f('2026-10-20'), 40_000)],
        );

        $this->assertSame(0, $this->calcular([], '2026-10-19', cuota: $cuota)->moraPendiente);
        $this->assertSame(40_000, $this->calcular([], '2026-10-20', cuota: $cuota)->moraPendiente);
    }

    public function test_reprogramar_condonando_la_mora(): void
    {
        $cuota = $this->cuota(congelada: 40_000, condonada: 40_000, vencimiento: '2026-11-10');

        $this->assertSame(0, $this->calcular([], '2026-11-05', cuota: $cuota)->moraPendiente);
    }

    public function test_mora_pagada_y_condonada_se_descuentan_de_la_pendiente(): void
    {
        $abonos = [...self::pagoCompleto('2026-10-15'), new Abono(self::f('2026-10-15'), ConceptoAplicacion::Mora, 5_000)];
        $mora = $this->calcular($abonos, '2026-10-15', cuota: $this->cuota(condonada: 10_000));

        $this->assertSame(5_000, $mora->moraPagada);
        $this->assertSame(5_000, $mora->moraPendiente);
    }

    public function test_dias_atraso_del_credito_es_el_mayor(): void
    {
        $estado = new EstadoCredito(100_000, 20_000, Tasa::desdeTexto('10'), [$this->cuota()], new ReglasMora(), new ReglasCalendario([]));

        $this->assertSame(35, $this->mora()->diasAtrasoCredito($estado, [], self::f('2026-11-14')));
        $this->assertSame(0, $this->mora()->diasAtrasoCredito($estado, [1 => self::pagoCompleto('2026-10-15')], self::f('2026-11-14')));
    }
}
