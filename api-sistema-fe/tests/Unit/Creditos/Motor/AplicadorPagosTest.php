<?php

declare(strict_types=1);

namespace Tests\Unit\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\Aplicacion;
use App\Services\Creditos\Motor\Dto\CuotaVigente;
use App\Services\Creditos\Motor\Dto\EstadoCredito;
use App\Services\Creditos\Motor\Dto\PagoAAplicar;
use App\Services\Creditos\Motor\Dto\ReglasMora;
use App\Services\Creditos\Motor\Dto\ResultadoAplicacion;
use App\Services\Creditos\Motor\Enums\ConceptoAplicacion;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Excepciones\PagoExcedeDeuda;
use PHPUnit\Framework\TestCase;

/**
 * Crédito del ejemplo 1.5 (10 × 600, c1 31/01, c2 02/03, c3 01/04, c4 01/05, c5 31/05) y del
 * ejemplo 1.6 (1 × 1,200, vence sábado 10/10/2026).
 */
class AplicadorPagosTest extends TestCase
{
    use ConstruyeCreditos;

    /** @param list<PagoAAplicar> $pagos */
    private function aplicar(EstadoCredito $estado, array $pagos, string $fecha): ResultadoAplicacion
    {
        return $this->aplicador()->aplicar($estado, $pagos, self::f($fecha));
    }

    /** @return list<PagoAAplicar> */
    private function pagosCuotas1a3(): array
    {
        return [
            $this->pago('p1', '2026-01-31', 60_000),
            $this->pago('p2', '2026-03-02', 60_000),
            $this->pago('p3', '2026-04-01', 60_000),
        ];
    }

    /** @return list<array{string, int, string, int}> [pago, cuota, concepto, monto] */
    private static function resumen(ResultadoAplicacion $r, ?string $pago = null): array
    {
        $filas = array_filter($r->aplicaciones, static fn (Aplicacion $a): bool => $pago === null || $a->referenciaPago === $pago);

        return array_values(array_map(
            static fn (Aplicacion $a): array => [$a->referenciaPago, $a->numeroCuota, $a->concepto->value, $a->monto],
            $filas,
        ));
    }

    private function conCondonacion(EstadoCredito $e, int $moraCondonada): EstadoCredito
    {
        $c = $e->cuotas[0];
        $cuota = new CuotaVigente(
            $c->numero, $c->fechaInicioPeriodo, $c->fechaVencimiento, $c->fechaVencimientoOriginal,
            $c->montoCapital, $c->montoInteres, moraCondonada: $moraCondonada,
        );

        return new EstadoCredito($e->montoCapital, $e->interesTotal, $e->tasaInteresMinimo, [$cuota], $e->reglasMora, $e->calendario);
    }

    // ---- Ejemplo 1.6 y fin del crédito ----

    public function test_ejemplo_1_6_paga_1400_interes_capital_mora_y_finaliza(): void
    {
        $r = $this->aplicar($this->creditoEjemplo16(), [$this->pago('p', '2026-10-15', 140_000)], '2026-10-15');

        $this->assertSame([
            ['p', 1, 'interes', 20_000],
            ['p', 1, 'capital', 100_000],
            ['p', 1, 'mora', 20_000],
        ], self::resumen($r));
        $this->assertTrue($r->finalizado);
        $this->assertSame(5, $r->cuota(1)->diasAtrasoAlPagar);
    }

    public function test_ejemplo_1_6_paga_solo_1200_la_mora_queda_pendiente_y_no_finaliza(): void
    {
        $r = $this->aplicar($this->creditoEjemplo16(), [$this->pago('p', '2026-10-15', 120_000)], '2026-10-30');

        $this->assertSame(EstadoCuota::Pagada, $r->cuota(1)->estado);
        $this->assertSame(20_000, $r->mora(1)->moraPendiente);   // ya no crece
        $this->assertFalse($r->finalizado);

        $r2 = $this->aplicar(
            $this->creditoEjemplo16(),
            [$this->pago('p', '2026-10-15', 120_000), $this->pago('m', '2026-10-30', 20_000)],
            '2026-10-30',
        );
        $this->assertTrue($r2->finalizado);
    }

    // ---- Orden de aplicación (1.7, 12.6) ----

    public function test_pago_que_cubre_dos_cuotas_mas_mora(): void
    {
        // c1 40 días de atraso (800), c2 10 días (200).
        $estado = $this->estado(
            $this->cronogramaCada30Dias(100_000, '20', 2, '2026-01-01'), 100_000, '10',
            new ReglasMora(topeTipo: TopeMoraTipo::SinTope),
        );

        $r = $this->aplicar($estado, [$this->pago('p', '2026-03-12', 220_000)], '2026-03-12');

        $this->assertSame([
            ['p', 1, 'interes', 10_000],
            ['p', 1, 'capital', 50_000],
            ['p', 2, 'interes', 10_000],
            ['p', 2, 'capital', 50_000],
            ['p', 1, 'mora', 80_000],
            ['p', 2, 'mora', 20_000],
        ], self::resumen($r));
        $this->assertTrue($r->finalizado);
    }

    public function test_pagar_la_cuota_antes_de_su_vencimiento_no_es_excedente(): void
    {
        $r = $this->aplicar($this->creditoEjemplo15(), [...$this->pagosCuotas1a3(), $this->pago('p4', '2026-04-20', 60_000)], '2026-04-20');

        $this->assertSame(0, $r->pago('p4')->montoExcedente);
        $this->assertSame(EstadoCuota::Pagada, $r->cuota(4)->estado);
    }

    public function test_excedente_con_destino_adelanto_cubre_cuotas_siguientes(): void
    {
        // Quedan 7 cuotas de 600 y paga 1,000 → cuota 4 completa + 400 de la cuota 5 (1.5).
        $pagos = [...$this->pagosCuotas1a3(), $this->pago('p4', '2026-04-10', 100_000, destino: DestinoExcedente::Adelanto)];
        $r = $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-10');

        $this->assertSame(EstadoCuota::Pagada, $r->cuota(4)->estado);
        $this->assertSame(10_000, $r->cuota(5)->interesPagado);
        $this->assertSame(30_000, $r->cuota(5)->capitalPagado);
        $this->assertSame(0, $r->cuota(5)->interesCondonado);   // sin descuento de interés
        $this->assertSame(100_000, $r->pago('p4')->montoAplicado);
    }

    public function test_excedente_con_destino_devolver_queda_fuera_del_credito(): void
    {
        $pagos = [...$this->pagosCuotas1a3(), $this->pago('p4', '2026-04-10', 100_000)];
        $r = $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-10');

        $this->assertSame(60_000, $r->pago('p4')->montoAplicado);
        $this->assertSame(40_000, $r->pago('p4')->montoExcedente);
        $this->assertSame(0, $r->cuota(5)->capitalPagado);
    }

    public function test_pago_de_700_con_deuda_de_500_deja_200_de_excedente(): void
    {
        // La devolución de caja es de Fase 3; el motor informa el excedente.
        $estado = $this->estado($this->cronogramaCada30Dias(40_000, '25', 1, '2026-01-01'), 40_000);
        $r = $this->aplicar($estado, [$this->pago('p', '2026-01-31', 70_000)], '2026-01-31');

        $this->assertSame(50_000, $r->pago('p')->montoAplicado);
        $this->assertSame(20_000, $r->pago('p')->montoExcedente);
        $this->assertTrue($r->finalizado);
    }

    public function test_adelanto_que_supera_la_liquidacion_lanza_pago_excede_deuda(): void
    {
        // Liquidación al 10/04 = 3,700; adelantar 4,000 aplicaría más → usar Liquidar (12.5).
        $pagos = [...$this->pagosCuotas1a3(), $this->pago('p4', '2026-04-10', 400_000, destino: DestinoExcedente::Adelanto)];

        try {
            $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-10');
            $this->fail('Debía lanzar PagoExcedeDeuda');
        } catch (PagoExcedeDeuda $e) {
            $this->assertSame(400_000, $e->montoAplicable);
            $this->assertSame(370_000, $e->montoLiquidacion);
        }
    }

    public function test_el_mismo_monto_con_destino_devolver_no_se_rechaza(): void
    {
        $pagos = [...$this->pagosCuotas1a3(), $this->pago('p4', '2026-04-10', 400_000)];
        $r = $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-10');

        $this->assertSame(340_000, $r->pago('p4')->montoExcedente);
    }

    public function test_monto_exigible(): void
    {
        $estado = $this->creditoEjemplo15();
        $pagos = $this->pagosCuotas1a3();

        // Antes del vencimiento de la cuota 4: solo la próxima cuota.
        $this->assertSame(60_000, $this->aplicador()->montoExigible($estado, $pagos, self::f('2026-04-10')));
        // El día del vencimiento la cuota sigue siendo "la próxima por vencer", no vencida (12.11).
        $this->assertSame(60_000, $this->aplicador()->montoExigible($estado, $pagos, self::f('2026-05-01')));
        // 4 días después: cuota 4 vencida + mora 80 + cuota 5.
        $this->assertSame(128_000, $this->aplicador()->montoExigible($estado, $pagos, self::f('2026-05-05')));
    }

    // ---- Recálculo determinista (1.9, 1.22) ----

    public function test_anular_pago_intermedio_equivale_a_registrar_solo_los_validos(): void
    {
        $estado = $this->creditoEjemplo15();
        $p1 = $this->pago('p1', '2026-01-31', 60_000);
        $p3 = $this->pago('p3', '2026-04-01', 60_000);

        $validos = $this->aplicar($estado, [$p1, $p3], '2026-04-01');
        $desordenados = $this->aplicar($estado, [$p3, $p1], '2026-04-01');

        $this->assertEquals($validos, $desordenados);
        // Sin el pago de la cuota 2, el de la fecha de la cuota 3 cubre la cuota 2 (vencida).
        $this->assertSame([['p3', 2, 'interes', 10_000], ['p3', 2, 'capital', 50_000]], self::resumen($validos, 'p3'));
        $this->assertSame(EstadoCuota::Pendiente, $validos->cuota(3)->estado);
        $this->assertSame(60_000, $validos->mora(2)->moraPendiente);
    }

    public function test_anular_el_pago_que_cubrio_mora_la_devuelve_a_pendiente(): void
    {
        $estado = $this->creditoEjemplo16();
        $cuota = $this->pago('c', '2026-10-15', 120_000);
        $mora = $this->pago('m', '2026-10-15', 20_000);

        $this->assertTrue($this->aplicar($estado, [$cuota, $mora], '2026-10-20')->finalizado);

        $sinMora = $this->aplicar($estado, [$cuota], '2026-10-20');
        $this->assertSame(20_000, $sinMora->mora(1)->moraPendiente);
        $this->assertFalse($sinMora->finalizado);
    }

    public function test_condonacion_se_conserva_tras_anular_un_pago(): void
    {
        $estado = $this->conCondonacion($this->creditoEjemplo16(), 10_000);
        $cuota = $this->pago('c', '2026-10-15', 120_000);
        $mora = $this->pago('m', '2026-10-15', 10_000);

        $this->assertTrue($this->aplicar($estado, [$cuota, $mora], '2026-10-20')->finalizado);

        $sinMora = $this->aplicar($estado, [$cuota], '2026-10-20');
        $this->assertSame(10_000, $sinMora->mora(1)->moraCondonada);
        $this->assertSame(10_000, $sinMora->mora(1)->moraPendiente);
    }

    public function test_pago_retroactivo_reaplica_los_pagos_posteriores(): void
    {
        $estado = $this->estado(
            $this->cronogramaCada30Dias(100_000, '20', 2, '2026-01-01'), 100_000, '10',
            new ReglasMora(topeTipo: TopeMoraTipo::SinTope),
        );
        $posterior = $this->pago('pB', '2026-03-12', 220_000);
        $retroactivo = $this->pago('pA', '2026-02-05', 60_000);   // registrado después, fecha anterior

        $r = $this->aplicar($estado, [$posterior, $retroactivo], '2026-03-12');

        $this->assertSame('pA', $r->aplicaciones[0]->referenciaPago);
        $this->assertSame([['pA', 1, 'interes', 10_000], ['pA', 1, 'capital', 50_000]], self::resumen($r, 'pA'));
        // Mora real: c1 5 días (100) + c2 10 días (200); el resto del pago posterior es excedente.
        $this->assertSame(90_000, $r->pago('pB')->montoAplicado);
        $this->assertSame(130_000, $r->pago('pB')->montoExcedente);
        $this->assertTrue($r->finalizado);
    }

    public function test_pago_posterior_a_la_fecha_de_referencia_se_rechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aplicar($this->creditoEjemplo16(), [$this->pago('p', '2026-10-15', 1_000)], '2026-10-14');
    }

    // ---- Liquidación y operaciones de cierre (1.5, 1.11, 1.21, 12.8) ----

    public function test_liquidacion_ejemplo_1_5_deja_todas_las_cuotas_pagadas_y_500_condonado(): void
    {
        $pagos = [...$this->pagosCuotas1a3(), $this->pago('liq', '2026-04-16', 370_000, OrigenPago::Liquidacion)];
        $r = $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-16');

        $this->assertTrue($r->finalizado);
        $this->assertTrue($r->pago('liq')->fueLiquidacion);
        $this->assertSame(0, $r->pago('liq')->montoExcedente);
        $condonado = 0;
        for ($n = 1; $n <= 10; $n++) {
            $this->assertSame(EstadoCuota::Pagada, $r->cuota($n)->estado, "cuota {$n}");
            $this->assertSame(50_000, $r->cuota($n)->capitalPagado);
            $condonado += $r->cuota($n)->interesCondonado;
        }
        $this->assertSame(50_000, $condonado);
        $this->assertSame(10_000, $r->cuota(5)->interesPagado);
        $this->assertSame(10_000, $r->cuota(6)->interesCondonado);
    }

    public function test_venta_de_prenda_con_excedente_liquida_el_credito(): void
    {
        $pagos = [...$this->pagosCuotas1a3(), $this->pago('venta', '2026-04-16', 400_000, OrigenPago::VentaPrenda)];
        $r = $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-16');

        $this->assertTrue($r->pago('venta')->fueLiquidacion);
        $this->assertSame(30_000, $r->pago('venta')->montoExcedente);   // excedente_por_devolver
        $this->assertTrue($r->finalizado);
    }

    public function test_segunda_prenda_no_se_aplica_si_la_primera_cubrio_la_deuda(): void
    {
        $pagos = [
            ...$this->pagosCuotas1a3(),
            $this->pago('venta1', '2026-04-16', 400_000, OrigenPago::VentaPrenda),
            $this->pago('venta2', '2026-04-20', 100_000, OrigenPago::VentaPrenda),
        ];
        $r = $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-20');

        $this->assertSame(0, $r->pago('venta2')->montoAplicado);
        $this->assertSame(100_000, $r->pago('venta2')->montoExcedente);
    }

    public function test_venta_de_prenda_con_faltante_es_pago_normal_con_cronograma_intacto(): void
    {
        // 2,000 < liquidación 3,700: se aplica en orden (destino adelanto) y el interés sigue completo.
        $pagos = [...$this->pagosCuotas1a3(), $this->pago('venta', '2026-04-16', 200_000, OrigenPago::VentaPrenda, DestinoExcedente::Adelanto)];
        $r = $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-16');

        $this->assertFalse($r->pago('venta')->fueLiquidacion);
        $this->assertSame(200_000, $r->pago('venta')->montoAplicado);
        $this->assertSame(EstadoCuota::Pagada, $r->cuota(6)->estado);
        $this->assertSame(10_000, $r->cuota(7)->interesPagado);
        $this->assertSame(10_000, $r->cuota(7)->capitalPagado);
        $this->assertSame(0, array_sum(array_map(static fn ($c): int => $c->interesCondonado, $r->cuotas)));
        $this->assertFalse($r->finalizado);
        $this->assertSame([], $r->cierresInsuficientes);
    }

    public function test_anular_pago_anterior_a_una_liquidacion_la_marca_como_insuficiente(): void
    {
        // Sin el pago de la cuota 3, la liquidación del 16/04 debería ser 4,600, no 3,700 (12.8).
        $pagos = [
            $this->pago('p1', '2026-01-31', 60_000),
            $this->pago('p2', '2026-03-02', 60_000),
            $this->pago('liq', '2026-04-16', 370_000, OrigenPago::Liquidacion),
        ];
        $r = $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-16');

        $this->assertSame(['liq'], $r->cierresInsuficientes);
        $this->assertFalse($r->pago('liq')->fueLiquidacion);
        $this->assertFalse($r->finalizado);
    }

    public function test_renovacion_se_trata_como_operacion_de_cierre(): void
    {
        $pagos = [...$this->pagosCuotas1a3(), $this->pago('ren', '2026-04-16', 370_000, OrigenPago::Renovacion)];
        $r = $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-16');

        $this->assertTrue($r->pago('ren')->fueLiquidacion);
        $this->assertTrue($r->finalizado);
    }

    public function test_venta_que_liquido_y_ya_no_alcanza_se_marca(): void
    {
        $pagos = [
            $this->pago('p1', '2026-01-31', 60_000),
            $this->pago('p2', '2026-03-02', 60_000),
            $this->pago('venta', '2026-04-16', 400_000, OrigenPago::VentaPrenda, DestinoExcedente::Adelanto, esCierre: true),
        ];
        $r = $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-16');

        $this->assertSame(['venta'], $r->cierresInsuficientes);
    }

    // ---- Atraso, castigo, migración ----

    public function test_prenda_vuelve_a_custodia_cuando_el_cliente_se_pone_al_dia(): void
    {
        // Al 12/03: c1 40 días de atraso (mora topada a 600, 100% de la cuota), c2 10 días (200).
        $estado = $this->creditoEjemplo15();
        $antes = $this->aplicar($estado, [], '2026-03-12');
        $this->assertSame(40, max(array_map(static fn ($m): int => $m->diasAtraso, $antes->moraAFecha)));

        $despues = $this->aplicar($estado, [$this->pago('p', '2026-03-12', 200_000)], '2026-03-12');
        $this->assertSame(0, max(array_map(static fn ($m): int => $m->diasAtraso, $despues->moraAFecha)));
        $this->assertSame(0, $despues->moraPendienteTotal());
    }

    public function test_pago_posterior_al_castigo_es_recupero(): void
    {
        $estado = $this->creditoEjemplo15(reglasMora: new ReglasMora(fechaCongelamiento: self::f('2026-05-01')));
        $r = $this->aplicar($estado, [$this->pago('antes', '2026-04-01', 10_000), $this->pago('despues', '2026-05-10', 10_000)], '2026-05-10');

        $this->assertFalse($r->pago('antes')->esRecupero);
        $this->assertTrue($r->pago('despues')->esRecupero);
    }

    public function test_migracion_rapida_cuotas_pagadas_a_tiempo_sin_mora(): void
    {
        $pagos = array_map(
            fn (PagoAAplicar $p): PagoAAplicar => $this->pago((string) $p->referencia, $p->fechaPago->aTexto(), $p->monto, OrigenPago::SaldoInicial),
            $this->pagosCuotas1a3(),
        );
        $r = $this->aplicar($this->creditoEjemplo15(), $pagos, '2026-04-10');

        foreach ([1, 2, 3] as $n) {
            $this->assertSame(EstadoCuota::Pagada, $r->cuota($n)->estado);
            $this->assertSame(0, $r->cuota($n)->diasAtrasoAlPagar);
        }
        $this->assertSame([], array_filter($r->aplicaciones, static fn (Aplicacion $a): bool => $a->concepto === ConceptoAplicacion::Mora));
    }

    public function test_migracion_detallada_calcula_la_mora_real(): void
    {
        // Cuota 1 pagada 10 días tarde con 800: 600 de cuota + 200 de mora (600 × 10 / 30).
        $r = $this->aplicar($this->creditoEjemplo15(), [$this->pago('h1', '2026-02-10', 80_000, OrigenPago::SaldoInicial)], '2026-02-10');

        $this->assertSame([
            ['h1', 1, 'interes', 10_000],
            ['h1', 1, 'capital', 50_000],
            ['h1', 1, 'mora', 20_000],
        ], self::resumen($r));
        $this->assertSame(10, $r->cuota(1)->diasAtrasoAlPagar);
    }
}
