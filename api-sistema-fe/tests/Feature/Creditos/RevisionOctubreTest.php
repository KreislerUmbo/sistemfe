<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\TipoCastigo;
use App\Models\Creditos\Credito;
use App\Models\User;
use App\Services\Creditos\CastigoService;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\EscalamientoService;
use App\Services\Creditos\Motor\Dto\Aplicacion;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\SaldoAFavorService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Revisión 08-oct-2026: castigo automático tras una reversión, vista previa del pago con fecha
 * anterior y anulación de una devolución de saldo a favor.
 */
final class RevisionOctubreTest extends CreditosTestCase
{
    private User $admin;
    private int $sesionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario();
        $this->sesionId = $this->abrirCaja($this->admin)->id;
    }

    // ---- 1. Revertir un castigo ----

    public function test_tras_revertir_un_castigo_el_automatico_espera_de_nuevo_los_dias_de_castigo(): void
    {
        $cliente = $this->cliente();
        $credito = $this->activo($this->admin, $cliente, $this->datos($cliente->id, capital: 100_000, cuotas: 1, tope: TopeMoraTipo::SinTope));
        $this->hoy('2026-05-02');
        $this->assertSame(1, app(EscalamientoService::class)->ejecutar(Fecha::desdeTexto('2026-05-02')));

        $this->hoy('2026-06-01');
        app(CastigoService::class)->revertir($credito, 'Se comprometió a pagar', $this->admin);

        // La noche siguiente ya no lo castiga, ni durante los 90 días desde la reversión.
        $this->assertSame(0, app(EscalamientoService::class)->ejecutar(Fecha::desdeTexto('2026-06-02')));
        $this->assertSame(0, app(EscalamientoService::class)->ejecutar(Fecha::desdeTexto('2026-08-30')));
        $this->assertSame(CreditoEstado::Activo, $credito->fresh()->estado);

        // Si sigue sin pagar, a los 91 días desde la reversión vuelve a castigarse.
        $this->hoy('2026-08-31');
        $this->assertSame(1, app(EscalamientoService::class)->ejecutar(Fecha::desdeTexto('2026-08-31')));
        $this->assertSame(CreditoEstado::Castigado, $credito->fresh()->estado);
    }

    public function test_un_castigo_manual_sigue_siendo_posible_tras_revertir(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-03-01');
        app(CastigoService::class)->castigar($credito, TipoCastigo::Manual, 'Incobrable', $this->admin);
        app(CastigoService::class)->revertir($credito, 'Error', $this->admin);
        app(CastigoService::class)->castigar($credito, TipoCastigo::Manual, 'Ahora sí', $this->admin);

        $this->assertSame(CreditoEstado::Castigado, $credito->fresh()->estado);
    }

    // ---- 2. Vista previa del pago con fecha anterior ----

    public function test_la_vista_previa_con_fecha_anterior_coincide_con_el_pago_registrado(): void
    {
        $credito = $this->activo($this->admin);   // cuota 1 de 600 vence el 31/01
        $this->hoy('2026-02-10');
        $cobros = app(CobroService::class);

        $aHoy = $cobros->cotizar($credito, 100_000, DestinoExcedente::Devolver, $this->admin);
        $aFecha = $cobros->cotizar($credito, 100_000, DestinoExcedente::Devolver, $this->admin, Fecha::desdeTexto('2026-02-08'));
        // Dos días menos de atraso: menos exigible y menos mora en el reparto.
        $this->assertLessThan($aHoy->exigible, $aFecha->exigible);
        $this->assertGreaterThan(0, self::mora($aFecha->lineas));
        $this->assertLessThan(self::mora($aHoy->lineas), self::mora($aFecha->lineas));

        $pago = $cobros->cobrar($credito, new SolicitudCobro(100_000, DestinoExcedente::Devolver, $this->efectivo->id, 'retro-preview',
            fechaPago: Fecha::desdeTexto('2026-02-08'), motivoRetroactivo: 'Pagó el sábado'), $this->admin);
        $this->assertSame(number_format($aFecha->montoAplicado / 100, 2, '.', ''), (string) $pago->monto_aplicado);
        $moraRegistrada = (int) round((float) $pago->aplicaciones()->where('vigente', true)->where('concepto', 'mora')->sum('monto') * 100);
        $this->assertSame(self::mora($aFecha->lineas), $moraRegistrada);
    }

    public function test_la_vista_previa_con_fecha_anterior_respeta_permiso_y_plazo(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-02-10');

        $this->assertRechazo(422, fn () => app(CobroService::class)->cotizar($credito, 60_000, DestinoExcedente::Devolver, $this->admin, Fecha::desdeTexto('2026-02-01')));
        $sinPermiso = $this->usuario(['creditos.ver', 'creditos.ver_todos', 'creditos.cobrar']);
        $this->assertRechazo(403, fn () => app(CobroService::class)->cotizar($credito, 60_000, DestinoExcedente::Devolver, $sinPermiso, Fecha::desdeTexto('2026-02-09')));
    }

    // ---- 4. Anular una devolución de saldo a favor ----

    public function test_anular_una_devolucion_repone_el_saldo_y_la_caja(): void
    {
        [$credito, $devolucion] = $this->saldoDevuelto();
        $saldos = app(SaldoAFavorService::class);
        $this->assertSame(4_000, $saldos->saldo($credito->cliente_id));
        $cajaTrasDevolver = $this->saldoCaja($this->sesionId);

        $saldos->anularDevolucion($credito->cliente, $devolucion, 'Se digitó mal el monto', $this->admin);

        $this->assertSame(10_000, $saldos->saldo($credito->cliente_id));
        $this->assertSame(number_format((float) $cajaTrasDevolver + 60, 2, '.', ''), $this->saldoCaja($this->sesionId));
        $fila = collect($saldos->movimientos($credito->cliente_id))->firstWhere('id', $devolucion);
        $this->assertTrue($fila['anulada']);
        $this->assertRechazo(422, fn () => $saldos->anularDevolucion($credito->cliente, $devolucion, 'Otra vez', $this->admin));
    }

    public function test_sin_permiso_solo_se_anula_la_devolucion_propia(): void
    {
        [$credito, $devolucion] = $this->saldoDevuelto();
        $cajero = $this->usuario(['creditos.ver', 'creditos.ver_todos', 'creditos.cobrar']);
        $this->abrirCaja($cajero);

        $this->assertRechazo(403, fn () => app(SaldoAFavorService::class)->anularDevolucion($credito->cliente, $devolucion, 'No es mía', $cajero));
        $this->assertSame(4_000, app(SaldoAFavorService::class)->saldo($credito->cliente_id));
    }

    /** Cobro de 700 sobre una cuota de 600 (100 de saldo a favor) y devolución de 60. @return array{0: Credito, 1: int} */
    private function saldoDevuelto(): array
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-01-31');
        app(CobroService::class)->cobrar($credito, new SolicitudCobro(70_000, DestinoExcedente::SaldoAFavor, $this->efectivo->id, uniqid('sf-', true)), $this->admin);
        $movimiento = app(SaldoAFavorService::class)->devolver($credito->cliente, 6_000, $this->efectivo->id, 'Lo pidió', $this->admin);

        return [$credito, $movimiento->id];
    }

    /** @param list<Aplicacion> $lineas */
    private static function mora(array $lineas): int
    {
        return array_sum(array_map(static fn (Aplicacion $a): int => $a->concepto->value === 'mora' ? $a->monto : 0, $lineas));
    }

    private function assertRechazo(int $estado, \Closure $accion): void
    {
        try {
            $accion();
            $this->fail("Se esperaba un rechazo {$estado}.");
        } catch (HttpException $e) {
            $this->assertSame($estado, $e->getStatusCode(), $e->getMessage());
        }
    }
}
