<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\TipoCastigo;
use App\Models\Creditos\Credito;
use App\Models\User;
use App\Services\Creditos\CastigoService;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\CondonacionService;
use App\Services\Creditos\ConsultaCreditoService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Reportes\CarteraEnVivo;
use App\Services\Creditos\Reportes\SituacionCartera;

/**
 * Fase 4d: la situación calculada en bloque para los reportes es idéntica, crédito por crédito,
 * a la del detalle (misma traducción BD → motor, mismo reparto). Si esto falla, un reporte y la
 * pantalla del crédito mostrarían números distintos.
 */
final class CarteraEnVivoTest extends CreditosTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario();
        $this->abrirCaja($this->admin);
    }

    public function test_en_bloque_coincide_con_el_detalle_de_cada_credito(): void
    {
        $alDia = $this->activo($this->admin);
        $atrasado = $this->activo($this->admin);
        $conCondonacion = $this->activo($this->admin);
        $castigado = $this->activo($this->admin, null, $this->datos($this->cliente()->id, capital: 100_000, cuotas: 1, tope: TopeMoraTipo::SinTope));

        $this->hoy('2026-01-31');
        app(CobroService::class)->cobrar($alDia, new SolicitudCobro(60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'cev-1'), $this->admin);
        app(CobroService::class)->cobrar($conCondonacion, new SolicitudCobro(30_000, DestinoExcedente::Devolver, $this->efectivo->id, 'cev-2'), $this->admin);
        $this->hoy('2026-02-20');
        app(CondonacionService::class)->condonar($conCondonacion, $conCondonacion->cuotasVigentes()->first(), 1_000, 'Buen cliente', $this->admin);
        app(CastigoService::class)->castigar($castigado, TipoCastigo::Manual, 'Inubicable', $this->admin);

        $situaciones = collect(app(CarteraEnVivo::class)->calcular(Credito::query()->whereKey([$alDia->id, $atrasado->id, $conCondonacion->id, $castigado->id]), Fecha::desdeTexto('2026-02-20')))
            ->keyBy(fn (SituacionCartera $s) => $s->credito->id);

        $this->assertCount(4, $situaciones);
        foreach ([$alDia, $atrasado, $conCondonacion, $castigado] as $credito) {
            $detalle = app(ConsultaCreditoService::class)->detalle($credito->fresh(), $this->admin);
            $s = $situaciones[$credito->id];
            $this->assertSame($detalle->saldoCapital, $s->saldoCapital, "capital #{$credito->id}");
            $this->assertSame($detalle->saldoInteres, $s->saldoInteres, "interés #{$credito->id}");
            $this->assertSame($detalle->saldo->mora, $s->mora, "mora #{$credito->id}");
            $this->assertSame($detalle->saldo->cargos, $s->cargos, "cargos #{$credito->id}");
            $this->assertSame($detalle->diasAtraso, $s->diasAtraso, "atraso #{$credito->id}");
            $this->assertSame($detalle->saldo->cuotasPagadas, $s->cuotasPagadas, "pagadas #{$credito->id}");
        }
        $this->assertGreaterThan(0, $situaciones[$atrasado->id]->diasAtraso);
        $this->assertTrue($situaciones[$atrasado->id]->enRiesgo());
        $this->assertSame('16-30', $situaciones[$atrasado->id]->rangoAtraso());
    }

    public function test_rangos_de_morosidad(): void
    {
        $this->assertSame(['al_dia', '1-7', '1-7', '8-15', '16-30', '31-60', '60+'], array_map(
            SituacionCartera::rango(...), [0, 1, 7, 8, 30, 60, 61],
        ));
    }
}
