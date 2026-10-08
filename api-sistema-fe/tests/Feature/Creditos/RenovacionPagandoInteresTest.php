<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\MotivoCierre;
use App\Enums\Creditos\PagoEstado;
use App\Models\Cash\CashMovement;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\CorreccionService;
use App\Services\Creditos\Documentos\DocumentoCreditoService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\LiquidacionService;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\RenovacionService;
use App\Services\Creditos\Reportes\Periodo;
use App\Services\Creditos\Reportes\ReportesCarteraService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Renovación ampliada (1.21, 08-oct-2026): además de "se entrega la diferencia", la renovación con
 * entrega cero y la renovación en la que el cliente PAGA la diferencia — caso típico del
 * prestamista: le prestó 500 al 20%, vence hoy (debe 600), paga el interés y sigue con 500 un mes más.
 */
final class RenovacionPagandoInteresTest extends CreditosTestCase
{
    private User $admin;
    private int $sesionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario();
        $this->sesionId = $this->abrirCaja($this->admin)->id;
    }

    /** 500 al 20% sobre el total en 1 cuota a 30 días: el 31/01 debe 600. */
    private function prestamo(): Credito
    {
        return $this->activo($this->admin, null, $this->datos($this->cliente()->id, capital: 50_000, tasa: '20', cuotas: 1));
    }

    private function renovar(Credito $anterior, int $capital, string $fecha): Credito
    {
        return app(RenovacionService::class)->renovar($anterior, $this->datos($anterior->cliente_id, capital: $capital, cuotas: 1, desembolso: $fecha), null, $this->admin);
    }

    private function pagoRenovacion(Credito $anterior): CreditoPago
    {
        return CreditoPago::where('credito_id', $anterior->id)->where('origen', OrigenPago::Renovacion)->sole();
    }

    public function test_paga_el_interes_y_renueva_por_el_mismo_capital(): void
    {
        $anterior = $this->prestamo();
        $this->hoy('2026-01-31');   // vence hoy: debe 500 + 100

        $vista = app(RenovacionService::class)->preview($anterior, $this->datos($anterior->cliente_id, capital: 50_000, cuotas: 1, desembolso: '2026-01-31'), $this->admin);
        $this->assertSame([-10_000, 'cobro'], [$vista->entregaNeta, \App\Services\Creditos\Dto\PreviewRenovacion::movimiento($vista->entregaNeta)]);

        $saldoAntes = $this->saldoCaja($this->sesionId);
        $nuevo = $this->renovar($anterior, 50_000, '2026-01-31');

        // El anterior queda cerrado por renovación; el nuevo, activo por 500 con su propio número.
        $anterior->refresh();
        $this->assertSame([CreditoEstado::Finalizado, MotivoCierre::Renovacion], [$anterior->estado, $anterior->motivo_cierre]);
        $this->assertSame([CreditoEstado::Activo, '500.00', $anterior->id], [$nuevo->estado, (string) $nuevo->monto_capital, $nuevo->credito_renovado_id]);

        // Caja: entran los 100 del cliente y no sale nada.
        $this->assertSame(number_format((float) $saldoAntes + 100, 2, '.', ''), $this->saldoCaja($this->sesionId));
        $pago = $this->pagoRenovacion($anterior);
        $entrada = CashMovement::findOrFail($pago->cash_movement_id);
        $this->assertSame(['in', '100.00', 'credito_pago'], [$entrada->direction, (string) $entrada->amount, $entrada->type]);
        $this->assertSame(0, CashMovement::where('reference_type', 'credito_desembolso')->where('reference_id', $nuevo->id)->count());

        // La conciliación del día cuadra y el recibo muestra el desglose.
        $this->assertTrue(app(ReportesCarteraService::class)->conciliacion(Periodo::dia(Fecha::desdeTexto('2026-01-31')))['cuadra']);
        $desglose = (fn (CreditoPago $p) => $this->desgloseRenovacion($p))->call(app(DocumentoCreditoService::class), $pago);
        $this->assertSame($nuevo->numero_credito, $desglose['credito']);
        $this->assertSame(['500.00', '100.00'], [preg_replace('/[^\d.]/', '', $desglose['cubierto']), preg_replace('/[^\d.]/', '', $desglose['cliente'])]);
    }

    public function test_lo_que_paga_al_renovar_cuenta_como_cobrado_en_panel_y_agenda(): void
    {
        $anterior = $this->prestamo();
        $this->hoy('2026-01-31');
        $this->renovar($anterior, 50_000, '2026-01-31');

        // Solo los 100 del cliente: los 500 que cubre el crédito nuevo no son cobro.
        $panel = app(ReportesCarteraService::class)->panel($this->admin);
        $this->assertSame('100.00', $panel['cobranza_hoy']['cobrado']);
        $this->assertSame('100.00', collect($panel['cobrado_dias'])->firstWhere('fecha', '2026-01-31')['monto']);
        $cobrados = app(\App\Services\Creditos\ConsultaCreditoService::class)->cobradosHoy($this->admin);
        $this->assertSame([10_000], array_map(static fn ($c): int => $c->montoAplicado, $cobrados));
    }

    public function test_renovar_entregando_no_cuenta_como_cobrado(): void
    {
        $anterior = $this->prestamo();
        $this->hoy('2026-01-31');
        $this->renovar($anterior, 100_000, '2026-01-31');   // debe 600, pide 1,000 → se entregan 400

        $this->assertSame('0.00', app(ReportesCarteraService::class)->panel($this->admin)['cobranza_hoy']['cobrado']);
        $this->assertSame([], app(\App\Services\Creditos\ConsultaCreditoService::class)->cobradosHoy($this->admin));
    }

    public function test_si_ya_pago_el_interes_renueva_sin_movimiento_de_dinero(): void
    {
        $anterior = $this->prestamo();
        $this->hoy('2026-01-31');
        app(CobroService::class)->cobrar($anterior, new SolicitudCobro(10_000, DestinoExcedente::Devolver, $this->efectivo->id, 'renov-cero-1'), $this->admin);
        $movimientos = CashMovement::count();

        $this->renovar($anterior, 50_000, '2026-01-31');

        $this->assertSame(CreditoEstado::Finalizado, $anterior->fresh()->estado);
        $this->assertSame($movimientos, CashMovement::count());   // la renovación no movió dinero
    }

    public function test_renovacion_anticipada_cobra_solo_la_diferencia_con_la_liquidacion(): void
    {
        $anterior = $this->activo($this->admin, null, $this->datos($this->cliente()->id, capital: 50_000, tasa: '20', cuotas: 2));
        $this->hoy('2026-01-11');   // antes del primer vencimiento: la liquidación descuenta interés futuro
        $liquidacion = app(LiquidacionService::class)->calcular($anterior, Fecha::desdeTexto('2026-01-11'))->montoLiquidacion;
        $this->assertLessThan(70_000, $liquidacion);   // debe menos que capital + todo el interés

        $this->renovar($anterior, 50_000, '2026-01-11');

        $entrada = CashMovement::findOrFail($this->pagoRenovacion($anterior)->cash_movement_id);
        $this->assertSame(number_format(($liquidacion - 50_000) / 100, 2, '.', ''), (string) $entrada->amount);
    }

    public function test_anular_el_credito_nuevo_reabre_el_anterior_y_devuelve_lo_cobrado(): void
    {
        $anterior = $this->prestamo();
        $this->hoy('2026-01-31');
        $saldoAntes = $this->saldoCaja($this->sesionId);
        $nuevo = $this->renovar($anterior, 50_000, '2026-01-31');

        app(CorreccionService::class)->anular($nuevo, 'Se equivocó de monto', $this->admin);

        $this->assertSame(CreditoEstado::Activo, $anterior->fresh()->estado);
        $this->assertSame(PagoEstado::Anulado, $this->pagoRenovacion($anterior)->estado);
        $this->assertSame($saldoAntes, $this->saldoCaja($this->sesionId));   // los 100 se revirtieron
    }

    public function test_si_el_cliente_paga_hay_que_indicar_el_metodo(): void
    {
        $anterior = $this->prestamo();
        $this->hoy('2026-01-31');
        $datos = $this->datos($anterior->cliente_id, capital: 50_000, cuotas: 1, desembolso: '2026-01-31');
        $args = [];
        foreach ((new \ReflectionClass($datos))->getConstructor()->getParameters() as $p) {
            $args[$p->getName()] = $p->getName() === 'paymentMethodId' ? null : $datos->{$p->getName()};
        }

        try {
            app(RenovacionService::class)->renovar($anterior, new \App\Services\Creditos\Dto\DatosCredito(...$args), null, $this->admin);
            $this->fail('Sin método de pago no debía renovar.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('método con el que paga el cliente', $e->getMessage());
        }
        $this->assertSame(CreditoEstado::Activo, $anterior->fresh()->estado);
    }
}
