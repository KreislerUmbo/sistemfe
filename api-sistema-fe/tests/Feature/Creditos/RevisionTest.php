<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Http\Requests\Creditos\ConfiguracionCreditoRequest;
use App\Http\Requests\Creditos\CreditoDatosRequest;
use App\Http\Resources\Creditos\DetalleCreditoResource;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\AnulacionPagoService;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\ConsultaCreditoService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\RenovacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Casos encontrados en la revisión posterior a la Fase 3. */
class RevisionTest extends CreditosTestCase
{
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = $this->usuario();
        $this->abrirCaja($this->usuario);
    }

    private function cobrar(Credito $credito, string $fecha, int $monto): CreditoPago
    {
        $this->hoy($fecha);

        return app(CobroService::class)->cobrar($credito, new SolicitudCobro($monto, DestinoExcedente::Devolver, $this->efectivo->id, uniqid('r-', true)), $this->usuario);
    }

    public function test_el_detalle_y_el_estado_de_cuenta_devuelven_las_cuotas_vigentes(): void
    {
        $credito = $this->activo($this->usuario);

        $detalle = (new DetalleCreditoResource(app(ConsultaCreditoService::class)->detalle($credito->fresh(), $this->usuario)))->toArray(new Request());
        $estadoCuenta = app(ConsultaCreditoService::class)->estadoCuenta($credito->fresh(), $this->usuario);

        $this->assertCount(10, $detalle['cuotas']);
        $this->assertCount(10, $estadoCuenta->cuotasVigentes);
    }

    public function test_credito_con_cuotas_pagadas_y_mora_pendiente_aparece_en_cobranza_y_con_atraso(): void
    {
        $cliente = $this->cliente();
        // Ejemplo 1.6: paga la cuota de 1,200 cinco días tarde y queda 200 de mora.
        $credito = $this->activo($this->usuario, $cliente, $this->datos($cliente->id, capital: 100_000, cuotas: 1));
        $this->cobrar($credito, '2026-02-05', 120_000);
        $this->hoy('2026-02-06');

        $cobranza = array_map(fn ($d) => $d->credito->id, app(ConsultaCreditoService::class)->cobranzaDelDia($this->usuario));
        $conAtraso = app(ConsultaCreditoService::class)->listar(['con_atraso' => true], $this->usuario)->pluck('id')->all();

        $this->assertSame(CreditoEstado::Activo, $credito->fresh()->estado);
        $this->assertContains($credito->id, $cobranza);
        $this->assertContains($credito->id, $conAtraso);
    }

    public function test_no_se_aceptan_los_siete_dias_como_no_laborables_ni_repetidos(): void
    {
        foreach ([ConfiguracionCreditoRequest::class, CreditoDatosRequest::class] as $request) {
            $reglas = (new $request())->rules();
            $clave = fn (array $dias) => Validator::make(['dias_no_laborables' => $dias], array_intersect_key($reglas, array_flip(['dias_no_laborables', 'dias_no_laborables.*'])));

            $this->assertTrue($clave([1, 2, 3, 4, 5, 6, 7])->fails(), $request);
            $this->assertTrue($clave([7, 7])->fails(), $request);
            $this->assertFalse($clave([6, 7])->fails(), $request);
        }
    }

    public function test_no_se_anula_un_pago_cuyo_saldo_a_favor_ya_se_uso(): void
    {
        $credito = $this->activo($this->usuario);
        $this->hoy('2026-01-31');
        $conExcedente = app(CobroService::class)->cobrar($credito, new SolicitudCobro(70_000, DestinoExcedente::SaldoAFavor, $this->efectivo->id, 'saldo-a'), $this->usuario);
        $this->hoy('2026-02-10');
        app(CobroService::class)->cobrar($credito, new SolicitudCobro(10_000, DestinoExcedente::Devolver, null, 'saldo-b', usarSaldoAFavor: true), $this->usuario);

        try {
            DB::transaction(fn () => app(AnulacionPagoService::class)->anular($conExcedente, 'x', $this->usuario));
            $this->fail('El saldo a favor ya se usó: debía bloquear.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('ya se usó', $e->getMessage());
        }
    }

    public function test_anular_un_pago_anterior_a_una_renovacion_indica_anular_el_credito_nuevo(): void
    {
        $cliente = $this->cliente();
        $anterior = $this->activo($this->usuario, $cliente, $this->datos($cliente->id, capital: 100_000));
        $pago = $this->cobrar($anterior, '2026-01-31', 12_000);
        $this->hoy('2026-02-10');
        app(RenovacionService::class)->renovar($anterior, $this->datos($cliente->id, capital: 200_000, desembolso: '2026-02-10'), null, $this->usuario);

        try {
            DB::transaction(fn () => app(AnulacionPagoService::class)->anular($pago, 'x', $this->usuario));
            $this->fail('Debía bloquear: la renovación dejaría de cubrir la deuda.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('crédito de la renovación', $e->getMessage());
        }
    }
}
