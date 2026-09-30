<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\MotivoCierre;
use App\Enums\Creditos\PagoEstado;
use App\Models\Cash\CashSession;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Models\Creditos\CreditoPagoAplicacion;
use App\Models\User;
use App\Services\Creditos\AnulacionPagoService;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\Dto\SolicitudLiquidacion;
use App\Services\Creditos\LiquidacionService;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 03-api "Anulación" y "Liquidación": extorno con recálculo, caja, bloqueos y el ejemplo 1.7. */
class AnulacionLiquidacionTest extends CreditosTestCase
{
    private User $usuario;
    private CashSession $sesion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = $this->usuario();
        $this->sesion = $this->abrirCaja($this->usuario);
    }

    private function cobrar(Credito $credito, string $fecha, int $monto, ?User $quien = null): CreditoPago
    {
        $this->hoy($fecha);

        return app(CobroService::class)->cobrar($credito, new SolicitudCobro($monto, DestinoExcedente::Devolver, $this->efectivo->id, uniqid('c-', true)), $quien ?? $this->usuario);
    }

    /** Cuotas 1-3 pagadas en su vencimiento. @return list<CreditoPago> */
    private function pagarTres(Credito $credito): array
    {
        return [
            $this->cobrar($credito, '2026-01-31', 60_000),
            $this->cobrar($credito, '2026-03-02', 60_000),
            $this->cobrar($credito, '2026-04-01', 60_000),
        ];
    }

    /** @return array<int, array<string, string>> estado persistido de las cuotas vigentes */
    private function foto(Credito $credito): array
    {
        return $credito->cuotasVigentes()->get()->map(fn ($c) => [
            'estado' => $c->estado->value, 'capital' => $c->capital_pagado, 'interes' => $c->interes_pagado, 'mora' => $c->mora_pagada,
        ])->all();
    }

    public function test_anular_un_pago_intermedio_deja_lo_mismo_que_registrar_solo_los_validos(): void
    {
        [$credito, $otro] = [$this->activo($this->usuario), $this->activo($this->usuario)];
        [, $p2] = $this->pagarTres($credito);
        $this->cobrar($otro, '2026-01-31', 60_000);
        $this->cobrar($otro, '2026-04-01', 60_000);

        app(AnulacionPagoService::class)->anular($p2, 'Pago registrado dos veces', $this->usuario);

        $this->assertSame(PagoEstado::Anulado, $p2->fresh()->estado);
        $this->assertSame($this->foto($otro), $this->foto($credito));
        $this->assertSame(0, CreditoPagoAplicacion::where('pago_id', $p2->id)->where('vigente', true)->count());
        $this->assertGreaterThan(0, CreditoPagoAplicacion::where('pago_id', $p2->id)->where('vigente', false)->count());
    }

    public function test_anular_revierte_la_caja_en_la_sesion_actual_aunque_la_original_este_cerrada(): void
    {
        $credito = $this->activo($this->usuario);
        [$p1] = $this->pagarTres($credito);
        $this->sesion->update(['status' => 'closed']);
        $nueva = $this->abrirCaja($this->usuario);

        app(AnulacionPagoService::class)->anular($p1, 'Error de cobro', $this->usuario);

        $this->assertSame('-600.00', $this->saldoCaja($nueva->id));
    }

    public function test_cajero_anula_su_propio_pago_con_su_caja_abierta_pero_no_uno_ajeno(): void
    {
        $credito = $this->activo($this->usuario);
        $cajero = $this->usuario(['creditos.ver', 'creditos.ver_todos', 'creditos.cobrar']);
        $this->abrirCaja($cajero);
        $propio = $this->cobrar($credito, '2026-01-31', 60_000, $cajero);
        $ajeno = $this->cobrar($credito, '2026-03-02', 60_000);

        try {
            app(AnulacionPagoService::class)->anular($ajeno, 'x', $cajero);
            $this->fail('No debía anular un pago ajeno sin creditos.anular_pago.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(PagoEstado::Anulado, app(AnulacionPagoService::class)->anular($propio, 'Me equivoqué de monto', $cajero)->estado);
    }

    public function test_liquidacion_del_ejemplo_1_7_persistida(): void
    {
        $credito = $this->activo($this->usuario);
        $this->pagarTres($credito);
        $this->hoy('2026-04-16');

        $this->assertSame(370_000, app(LiquidacionService::class)->cotizar($credito, null, $this->usuario)->montoLiquidacion);
        $pago = app(LiquidacionService::class)->liquidar($credito, new SolicitudLiquidacion(370_000, $this->efectivo->id, 'liq-1'), $this->usuario);

        $credito->refresh();
        $this->assertTrue($pago->es_cierre);
        $this->assertSame(CreditoEstado::Finalizado, $credito->estado);
        $this->assertSame(MotivoCierre::LiquidacionAnticipada, $credito->motivo_cierre);
        $cuotas = $credito->cuotasVigentes()->get();
        $this->assertTrue($cuotas->every(fn ($c) => $c->estado === EstadoCuota::Pagada));
        $this->assertSame(500.0, (float) $cuotas->sum(fn ($c) => (float) $c->interes_condonado));
    }

    public function test_anular_la_liquidacion_reabre_el_credito_y_revierte_el_condonado(): void
    {
        $credito = $this->activo($this->usuario);
        $this->pagarTres($credito);
        $this->hoy('2026-04-16');
        $pago = app(LiquidacionService::class)->liquidar($credito, new SolicitudLiquidacion(370_000, $this->efectivo->id, 'liq-2'), $this->usuario);

        app(AnulacionPagoService::class)->anular($pago, 'El cliente se arrepintió', $this->usuario);

        $credito->refresh();
        $this->assertSame(CreditoEstado::Activo, $credito->estado);
        $this->assertNull($credito->motivo_cierre);
        $this->assertSame(0.0, (float) $credito->cuotasVigentes()->get()->sum(fn ($c) => (float) $c->interes_condonado));
    }

    public function test_no_se_anula_un_pago_si_la_liquidacion_posterior_deja_de_alcanzar(): void
    {
        $credito = $this->activo($this->usuario);
        [, , $p3] = $this->pagarTres($credito);
        $this->hoy('2026-04-16');
        app(LiquidacionService::class)->liquidar($credito, new SolicitudLiquidacion(370_000, $this->efectivo->id, 'liq-3'), $this->usuario);

        try {
            DB::transaction(fn () => app(AnulacionPagoService::class)->anular($p3, 'x', $this->usuario));
            $this->fail('Debía bloquear por cierre insuficiente (1.8 a).');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('Anula primero', $e->getMessage());
        }
        $this->assertSame(PagoEstado::Valido, $p3->fresh()->estado);
        $this->assertSame(CreditoEstado::Finalizado, $credito->fresh()->estado);
    }

    public function test_liquidar_con_monto_insuficiente_se_rechaza(): void
    {
        $credito = $this->activo($this->usuario);
        $this->pagarTres($credito);
        $this->hoy('2026-04-16');

        $this->expectException(HttpException::class);
        app(LiquidacionService::class)->liquidar($credito, new SolicitudLiquidacion(369_990, $this->efectivo->id, 'liq-4'), $this->usuario);
    }
}
