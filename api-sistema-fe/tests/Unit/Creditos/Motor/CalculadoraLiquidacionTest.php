<?php

declare(strict_types=1);

namespace Tests\Unit\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\CuotaVigente;
use App\Services\Creditos\Motor\Dto\EstadoCredito;
use App\Services\Creditos\Motor\Dto\Liquidacion;
use App\Services\Creditos\Motor\Dto\PagoAAplicar;
use App\Services\Creditos\Motor\Dto\ReglasMora;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use PHPUnit\Framework\TestCase;

/** Crédito del ejemplo 1.5: c1 31/01, c2 02/03, c3 01/04, c4 01/05 (período 01/04 → 01/05, 30 días). */
class CalculadoraLiquidacionTest extends TestCase
{
    use ConstruyeCreditos;

    /** @param list<PagoAAplicar> $pagos */
    private function liquidar(EstadoCredito $estado, array $pagos, string $fecha): Liquidacion
    {
        $pagado = $this->aplicador()->aplicar($estado, $pagos, self::f($fecha));

        return $this->liquidador()->calcular($estado, $pagado, self::f($fecha));
    }

    /** @return list<PagoAAplicar> cuotas 1-3 pagadas en su vencimiento */
    private function pagosCuotas1a3(): array
    {
        return [
            $this->pago('p1', '2026-01-31', 60_000),
            $this->pago('p2', '2026-03-02', 60_000),
            $this->pago('p3', '2026-04-01', 60_000),
        ];
    }

    public function test_ejemplo_1_5_liquida_a_mitad_del_periodo_de_la_cuota_4(): void
    {
        $liq = $this->liquidar($this->creditoEjemplo15(), $this->pagosCuotas1a3(), '2026-04-16');

        $this->assertSame(350_000, $liq->capitalPendiente);
        $this->assertSame(30_000, $liq->interesCobrado);
        $this->assertSame(35_000, $liq->interesDevengado);   // 300 + 100 × 15/30
        $this->assertSame(50_000, $liq->interesMinimo);
        $this->assertSame(50_000, $liq->interesFinal);
        $this->assertSame(20_000, $liq->interesACobrar);
        $this->assertSame(50_000, $liq->interesCondonado);
        $this->assertSame(370_000, $liq->montoLiquidacion);

        // Cuotas 4 y 5 con interés 100 cobrado; 6-10 con 100 condonado; capital 500 en cada una.
        $this->assertCount(7, $liq->reparto);
        foreach ($liq->reparto as $r) {
            $this->assertSame(50_000, $r->capital);
            $this->assertSame($r->numeroCuota <= 5 ? 10_000 : 0, $r->interes, "cuota {$r->numeroCuota}");
            $this->assertSame($r->numeroCuota <= 5 ? 0 : 10_000, $r->interesCondonado, "cuota {$r->numeroCuota}");
        }
    }

    public function test_sin_interes_minimo_serian_3550(): void
    {
        $liq = $this->liquidar($this->creditoEjemplo15('0'), $this->pagosCuotas1a3(), '2026-04-16');

        $this->assertSame(355_000, $liq->montoLiquidacion);
    }

    public function test_liquidacion_al_dia_siguiente_cobra_el_minimo(): void
    {
        $liq = $this->liquidar($this->creditoEjemplo15(), [], '2026-01-02');

        $this->assertSame(333, $liq->interesDevengado);           // 100 × 1/30 = 3.33
        $this->assertSame(50_000, $liq->interesFinal);
        $this->assertSame(550_000, $liq->montoLiquidacion);
    }

    public function test_interes_final_nunca_supera_el_pactado(): void
    {
        $liq = $this->liquidar($this->creditoEjemplo15('50'), [], '2026-01-02');

        $this->assertSame(100_000, $liq->interesFinal);
        $this->assertSame(600_000, $liq->montoLiquidacion);
    }

    public function test_cuota_vencida_impaga_cobra_su_interes_completo_y_su_mora(): void
    {
        // Pagó 1 y 2; la cuota 3 venció el 01/04 y liquida el 16/04: 15 días × 600 / 30 = 300 de mora.
        $pagos = array_slice($this->pagosCuotas1a3(), 0, 2);
        $liq = $this->liquidar($this->creditoEjemplo15(reglasMora: new ReglasMora(topeTipo: TopeMoraTipo::SinTope)), $pagos, '2026-04-16');

        $this->assertSame(400_000, $liq->capitalPendiente);
        $this->assertSame(30_000, $liq->interesACobrar);          // 500 − 200 cobrado
        $this->assertSame(30_000, $liq->moraPendiente);
        $this->assertSame(460_000, $liq->montoLiquidacion);

        $cuota3 = $liq->reparto[0];
        $this->assertSame(3, $cuota3->numeroCuota);
        $this->assertSame(10_000, $cuota3->interes);
        $this->assertSame(0, $cuota3->interesCondonado);
        $this->assertSame(30_000, $cuota3->mora);
    }

    public function test_cargos_pendientes_se_suman_sin_descuento(): void
    {
        $base = $this->creditoEjemplo15();
        $cuotas = $base->cuotas;
        $c4 = $cuotas[3];
        $cuotas[3] = new CuotaVigente(
            $c4->numero, $c4->fechaInicioPeriodo, $c4->fechaVencimiento, $c4->fechaVencimientoOriginal,
            $c4->montoCapital, $c4->montoInteres, cargoMonto: 2_000,
        );
        $estado = new EstadoCredito($base->montoCapital, $base->interesTotal, $base->tasaInteresMinimo, $cuotas, $base->reglasMora, $base->calendario);

        $liq = $this->liquidar($estado, $this->pagosCuotas1a3(), '2026-04-16');

        $this->assertSame(2_000, $liq->cargoPendiente);
        $this->assertSame(372_000, $liq->montoLiquidacion);
        $this->assertSame(2_000, $liq->reparto[0]->cargo);
    }

    public function test_renovacion_liquidacion_300_y_nuevo_de_1000_entrega_700(): void
    {
        // 1,000 al 20% en 10 cuotas de 120 (100 + 20); pagó 7 y renueva el día del 7.º vencimiento.
        $cronograma = $this->cronogramaCada30Dias(100_000, '20', 10, '2026-01-01');
        $estado = $this->estado($cronograma, 100_000);
        $pagos = [];
        for ($i = 0; $i < 7; $i++) {
            $pagos[] = $this->pago("p{$i}", $cronograma->cuotas[$i]->fechaVencimiento->aTexto(), 12_000);
        }
        $fecha = $cronograma->cuotas[6]->fechaVencimiento->aTexto();

        $liq = $this->liquidar($estado, $pagos, $fecha);

        $this->assertSame(30_000, $liq->montoLiquidacion);
        $this->assertSame(70_000, 100_000 - $liq->montoLiquidacion);   // entrega neta (1.21)
    }

    public function test_exige_el_reparto_calculado_a_la_misma_fecha(): void
    {
        $estado = $this->creditoEjemplo15();
        $pagado = $this->aplicador()->aplicar($estado, [], self::f('2026-04-15'));

        $this->expectException(\InvalidArgumentException::class);
        $this->liquidador()->calcular($estado, $pagado, self::f('2026-04-16'));
    }
}
