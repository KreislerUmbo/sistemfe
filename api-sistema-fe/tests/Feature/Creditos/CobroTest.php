<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPagoAplicacion;
use App\Models\User;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\Excepciones\PagoExcedeDeuda;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\SaldoAFavorService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 03-api "Cobro": aplicaciones, acumulados, excedente (devolver/adelanto/saldo a favor) y retroactivo. */
class CobroTest extends CreditosTestCase
{
    private User $usuario;
    private int $sesionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = $this->usuario();
        $this->sesionId = $this->abrirCaja($this->usuario)->id;
    }

    private function cobrar(Credito $credito, int $monto, DestinoExcedente $destino = DestinoExcedente::Devolver, ?string $fechaPago = null, bool $saldo = false): \App\Models\Creditos\CreditoPago
    {
        return app(CobroService::class)->cobrar($credito, new SolicitudCobro(
            $monto, $destino, $saldo ? null : $this->efectivo->id, uniqid('clave-', true), $saldo,
            fechaPago: $fechaPago === null ? null : Fecha::desdeTexto($fechaPago),
            motivoRetroactivo: $fechaPago === null ? null : 'Cobrado en campo sin señal',
        ), $this->usuario);
    }

    public function test_cobro_de_la_primera_cuota_guarda_aplicaciones_acumulados_y_caja(): void
    {
        $credito = $this->activo($this->usuario);
        $this->hoy('2026-01-31');

        $pago = $this->cobrar($credito, 60_000);

        $this->assertMatchesRegularExpression('/^RC-\d{8}$/', $pago->numero_recibo);
        $this->assertSame('600.00', $pago->monto_aplicado);
        $cuota = $credito->cuotasVigentes()->first();
        $this->assertSame(EstadoCuota::Pagada, $cuota->estado);
        $this->assertSame('500.00', $cuota->capital_pagado);
        $this->assertSame('100.00', $cuota->interes_pagado);
        $this->assertSame(0, $cuota->dias_atraso_al_pagar);
        $this->assertSame(2, CreditoPagoAplicacion::where('pago_id', $pago->id)->where('vigente', true)->count());
        $this->assertSame('-4400.00', $this->saldoCaja($this->sesionId));
    }

    public function test_pago_de_700_con_deuda_de_500_devuelve_200_y_la_caja_cuadra(): void
    {
        $cliente = $this->cliente();
        $credito = $this->activo($this->usuario, $cliente, $this->datos($cliente->id, capital: 40_000, tasa: '25', cuotas: 1));
        $this->hoy('2026-01-31');

        $pago = $this->cobrar($credito, 70_000);

        $this->assertSame('500.00', $pago->monto_aplicado);
        $this->assertSame('200.00', $pago->monto_excedente);
        $this->assertSame(DestinoExcedente::Devolver, $pago->destino_excedente);
        $this->assertSame(CreditoEstado::Finalizado, $credito->fresh()->estado);
        // Desembolso 400, entra 700, sale 200: neto +100 (el interés cobrado).
        $this->assertSame('100.00', $this->saldoCaja($this->sesionId));
    }

    public function test_excedente_con_destino_adelanto_cubre_la_cuota_siguiente(): void
    {
        $credito = $this->activo($this->usuario);
        $this->hoy('2026-01-31');

        $this->cobrar($credito, 100_000, DestinoExcedente::Adelanto);

        $cuotas = $credito->cuotasVigentes()->get();
        $this->assertSame(EstadoCuota::Pagada, $cuotas[0]->estado);
        $this->assertSame('100.00', $cuotas[1]->interes_pagado);
        $this->assertSame('300.00', $cuotas[1]->capital_pagado);
    }

    public function test_excedente_a_saldo_a_favor_y_uso_del_saldo_sin_caja(): void
    {
        $credito = $this->activo($this->usuario);
        $this->hoy('2026-01-31');
        $this->cobrar($credito, 70_000, DestinoExcedente::SaldoAFavor);
        $this->assertSame(10_000, app(SaldoAFavorService::class)->saldo($credito->cliente_id));
        $cajaAntes = $this->saldoCaja($this->sesionId);

        $this->hoy('2026-02-10');
        $pago = $this->cobrar($credito, 10_000, saldo: true);

        $this->assertNull($pago->cash_movement_id);
        $this->assertSame('100.00', $pago->monto_aplicado);
        $this->assertSame(0, app(SaldoAFavorService::class)->saldo($credito->cliente_id));
        $this->assertSame($cajaAntes, $this->saldoCaja($this->sesionId));
    }

    public function test_adelanto_que_supera_la_liquidacion_se_rechaza(): void
    {
        $credito = $this->activo($this->usuario);
        $this->hoy('2026-01-02');

        $this->expectException(PagoExcedeDeuda::class);
        $this->cobrar($credito, 600_000, DestinoExcedente::Adelanto);
    }

    public function test_pago_retroactivo_reaplica_los_posteriores(): void
    {
        $credito = $this->activo($this->usuario);
        $this->hoy('2026-02-03');
        // Hoy, 3 días tarde: 600 de cuota + 60 de mora (600 × 3 / 30).
        $deHoy = $this->cobrar($credito, 66_000);
        $this->assertSame('660.00', $deHoy->monto_aplicado);

        // Después se registra un cobro real del 31/01: cubre la cuota 1 a tiempo y el de hoy se
        // reaplica a la cuota 2 (600) y al interés de la cuota 3 (60).
        $retro = $this->cobrar($credito, 60_000, fechaPago: '2026-01-31');

        $cuotas = $credito->cuotasVigentes()->get();
        $this->assertSame(0, $cuotas[0]->dias_atraso_al_pagar);
        $this->assertSame('0.00', $cuotas[0]->mora_pagada);
        $this->assertSame(EstadoCuota::Pagada, $cuotas[1]->estado);
        $this->assertSame('60.00', $cuotas[2]->interes_pagado);
        $this->assertSame(
            ['capital' => '500.00', 'interes' => '100.00'],
            CreditoPagoAplicacion::where('pago_id', $retro->id)->where('vigente', true)->get()
                ->mapWithKeys(fn ($a) => [$a->concepto->value => $a->monto])->sortKeys()->all(),
        );
        $this->assertSame(2, (int) CreditoPagoAplicacion::where('credito_id', $credito->id)->where('vigente', true)->max('generacion'));
        $this->assertSame('-3740.00', $this->saldoCaja($this->sesionId));
    }

    public function test_retroactivo_fuera_de_plazo_o_sin_permiso_se_rechaza(): void
    {
        $credito = $this->activo($this->usuario);
        $this->hoy('2026-02-10');

        try {
            $this->cobrar($credito, 60_000, fechaPago: '2026-02-01');
            $this->fail('Debía rechazar más de 3 días atrás.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $cajero = $this->usuario(['creditos.ver', 'creditos.ver_todos', 'creditos.cobrar']);
        $this->abrirCaja($cajero);
        try {
            app(CobroService::class)->cobrar($credito, new SolicitudCobro(
                60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'clave-sin-permiso', fechaPago: Fecha::desdeTexto('2026-02-09'), motivoRetroactivo: 'x',
            ), $cajero);
            $this->fail('Debía exigir creditos.pago_fecha_anterior.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_cobrador_no_ve_ni_cobra_creditos_fuera_de_su_cartera(): void
    {
        $credito = $this->activo($this->usuario);
        $cobrador = $this->usuario(['creditos.ver', 'creditos.cobrar']);
        $this->abrirCaja($cobrador);

        try {
            app(CobroService::class)->cobrar($credito, new SolicitudCobro(60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'clave-cartera'), $cobrador);
            $this->fail('Debía responder 404 fuera de la cartera.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        DB::table('cartera_asignaciones')->insert([
            'cobrador_id' => $cobrador->id, 'tipo' => 'cliente', 'referencia_id' => $credito->cliente_id,
            'vigente_desde' => '2026-01-01', 'asignado_por' => $this->usuario->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->hoy('2026-01-31');
        $pago = app(CobroService::class)->cobrar($credito, new SolicitudCobro(60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'clave-cartera-2'), $cobrador);
        $this->assertSame('600.00', $pago->monto_aplicado);
    }
}
