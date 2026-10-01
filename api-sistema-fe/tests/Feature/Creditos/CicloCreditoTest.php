<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\AccionMoraReprogramacion;
use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\MotivoCierre;
use App\Enums\Creditos\OrigenRegistro;
use App\Enums\Creditos\PagoEstado;
use App\Enums\Creditos\TipoCastigo;
use App\Models\Cash\CashSession;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\AnulacionPagoService;
use App\Services\Creditos\CastigoService;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\CondonacionService;
use App\Services\Creditos\CorreccionService;
use App\Services\Creditos\Documentos\FormatoDocumento;
use App\Services\Creditos\Dto\PagoHistorico;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\Dto\SolicitudReprogramacion;
use App\Services\Creditos\EscalamientoService;
use App\Services\Creditos\LimitesService;
use App\Services\Creditos\MigracionService;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\Enums\ReglaLimite;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\RenovacionService;
use App\Services\Creditos\ReprogramacionService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 03-api: corregir, anular, reprogramar, condonar, castigo, renovación y migración con persistencia y caja. */
class CicloCreditoTest extends CreditosTestCase
{
    private User $usuario;
    private CashSession $sesion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = $this->usuario();
        $this->sesion = $this->abrirCaja($this->usuario);
    }

    private function cobrar(Credito $credito, string $fecha, int $monto): CreditoPago
    {
        $this->hoy($fecha);

        return app(CobroService::class)->cobrar($credito, new SolicitudCobro($monto, DestinoExcedente::Devolver, $this->efectivo->id, uniqid('c-', true)), $this->usuario);
    }

    private function mora(Credito $credito, int $numeroCuota, string $fecha): int
    {
        $this->hoy($fecha);
        $carga = app(\App\Services\Creditos\CargadorCredito::class)->cargar($credito->fresh());

        return app(\App\Services\Creditos\Motor\AplicadorPagos::class)
            ->aplicar($carga->estado, $carga->pagos, Fecha::desdeTexto($fecha))->mora($numeroCuota)->moraPendiente;
    }

    // ---- Corregir y anular ----

    public function test_corregir_genera_version_2_y_ajusta_el_desembolso(): void
    {
        $credito = $this->activo($this->usuario);

        $corregido = app(CorreccionService::class)->corregir($credito, $this->datos($credito->cliente_id, capital: 400_000), 'Monto mal digitado', $this->usuario);

        $this->assertSame(2, $corregido->version_cronograma_actual);
        $this->assertSame($credito->numero_credito, $corregido->numero_credito);
        $this->assertSame('4000.00', $corregido->monto_capital);
        $this->assertSame(10, $corregido->cuotasVigentes()->count());
        $this->assertSame(10, $corregido->cuotas()->where('version_cronograma', 1)->where('estado', EstadoCuota::Anulada)->count());
        $this->assertSame('-4000.00', $this->saldoCaja($this->sesion->id));   // -5000 +5000 (reverso) -4000
    }

    public function test_no_se_corrige_ni_anula_un_credito_con_pagos(): void
    {
        $credito = $this->activo($this->usuario);
        $this->cobrar($credito, '2026-01-31', 60_000);

        $this->expectException(HttpException::class);
        app(CorreccionService::class)->anular($credito, 'x', $this->usuario);
    }

    public function test_anular_credito_revierte_el_desembolso(): void
    {
        $credito = $this->activo($this->usuario);

        $anulado = app(CorreccionService::class)->anular($credito, 'Cliente desistió', $this->usuario);

        $this->assertSame(CreditoEstado::Anulado, $anulado->estado);
        $this->assertSame('0.00', $this->saldoCaja($this->sesion->id));
    }

    // ---- Reprogramar y condonar ----

    public function test_reprogramar_10_dias_con_mora_mantenida_y_cargo(): void
    {
        $credito = $this->activo($this->usuario);
        $this->cobrar($credito, '2026-01-31', 60_000);
        // Al 05/03 la cuota 2 (vence 02/03) lleva 3 días: 600 × 3 / 30 = 60 de mora.
        $this->hoy('2026-03-05');
        $solicitud = new SolicitudReprogramacion(2, 10, [], AccionMoraReprogramacion::Mantener, 2_000, 'Cliente enfermo', 'r-1');

        app(ReprogramacionService::class)->reprogramar($credito, $solicitud, $this->usuario);

        $cuotas = $credito->cuotasVigentes()->get();
        $this->assertSame('2026-03-12', $cuotas[1]->fecha_vencimiento->format('Y-m-d'));
        $this->assertSame('2026-03-02', $cuotas[1]->fecha_vencimiento_original->format('Y-m-d'));
        $this->assertSame('2026-04-11', $cuotas[2]->fecha_vencimiento->format('Y-m-d'));
        $this->assertSame('2026-01-31', $cuotas[0]->fecha_vencimiento->format('Y-m-d'));   // pagada, intacta
        $this->assertSame('60.00', $cuotas[1]->mora_congelada);
        $this->assertSame('20.00', $cuotas[1]->cargo_monto);
        // Hasta la nueva fecha no corre mora nueva: solo la congelada. Y el cobro del 31/01, anterior
        // a la reprogramación, sigue cubriendo la cuota 1 completa (no "ve" la congelada).
        $this->assertSame(6_000, $this->mora($credito, 2, '2026-03-12'));
        $this->assertSame(EstadoCuota::Pagada, $credito->cuotasVigentes()->first()->estado);
        $this->assertSame('0.00', $credito->cuotasVigentes()->get()[1]->mora_pagada);
    }

    public function test_reprogramar_condonando_la_mora(): void
    {
        $credito = $this->activo($this->usuario);
        $this->hoy('2026-02-05');
        $solicitud = new SolicitudReprogramacion(1, 10, [], AccionMoraReprogramacion::Condonar, 0, 'Acuerdo', 'r-2');

        app(ReprogramacionService::class)->reprogramar($credito, $solicitud, $this->usuario);

        $this->assertSame(0, $this->mora($credito, 1, '2026-02-10'));
    }

    public function test_no_se_anula_un_pago_anterior_a_una_reprogramacion(): void
    {
        $credito = $this->activo($this->usuario);
        $pago = $this->cobrar($credito, '2026-01-31', 60_000);
        $this->hoy('2026-02-05');
        app(ReprogramacionService::class)->reprogramar($credito, new SolicitudReprogramacion(2, 5, [], AccionMoraReprogramacion::Mantener, 0, 'x', 'r-3'), $this->usuario);

        try {
            app(AnulacionPagoService::class)->anular($pago, 'x', $this->usuario);
            $this->fail('Debía bloquear (1.8 b).');
        } catch (HttpException $e) {
            $this->assertStringContainsString('reprogramación', $e->getMessage());
        }
    }

    public function test_condonar_mora_y_finalizar(): void
    {
        $cliente = $this->cliente();
        $credito = $this->activo($this->usuario, $cliente, $this->datos($cliente->id, capital: 100_000, cuotas: 1));
        // Ejemplo 1.6: paga 1,200 cinco días tarde; queda 200 de mora.
        $this->cobrar($credito, '2026-02-05', 120_000);
        $this->assertSame(CreditoEstado::Activo, $credito->fresh()->estado);

        app(CondonacionService::class)->condonar($credito, $credito->cuotasVigentes()->first(), 20_000, 'Cliente puntual', $this->usuario);

        $this->assertSame(CreditoEstado::Finalizado, $credito->fresh()->estado);
        $this->assertSame('200.00', $credito->cuotasVigentes()->first()->mora_condonada);
    }

    public function test_no_se_condona_mas_de_lo_pendiente(): void
    {
        $credito = $this->activo($this->usuario);
        // Al 05/02 la cuota 1 lleva 5 días: 600 × 5 / 30 = 100.00 de mora.
        $this->hoy('2026-02-05');

        $this->expectException(HttpException::class);
        app(CondonacionService::class)->condonar($credito, $credito->cuotasVigentes()->first(), 10_010, 'x', $this->usuario);
    }

    public function test_credito_sin_mora_no_acumula_mora_y_el_contrato_lo_dice(): void
    {
        $cliente = $this->cliente();
        $credito = $this->activo($this->usuario, $cliente, $this->datos($cliente->id, capital: 100_000, cuotas: 1, cobraMora: false));

        $this->assertFalse($credito->cobra_mora);
        $this->assertSame(0, $this->mora($credito, 1, '2026-03-02'));   // vence 31/01, 30 días después
        $this->assertSame('Este crédito no cobra interés moratorio por atraso en los pagos.', FormatoDocumento::reglaMora($credito));
    }

    // ---- Castigo ----

    public function test_castigo_automatico_a_los_90_dias_bloquea_y_revertir_no_genera_mora_retroactiva(): void
    {
        $cliente = $this->cliente();
        $credito = $this->activo($this->usuario, $cliente, $this->datos($cliente->id, capital: 100_000, cuotas: 1, tope: \App\Services\Creditos\Motor\Enums\TopeMoraTipo::SinTope));
        // Vence el 31/01; al 01/05 tiene 90 días (no castiga); al 02/05, 91.
        $this->assertSame(0, app(EscalamientoService::class)->ejecutar(Fecha::desdeTexto('2026-05-01')));
        $this->hoy('2026-05-02');
        $this->assertSame(1, app(EscalamientoService::class)->ejecutar(Fecha::desdeTexto('2026-05-02')));
        $this->assertSame(0, app(EscalamientoService::class)->ejecutar(Fecha::desdeTexto('2026-05-02')));   // idempotente
        $this->assertSame(CreditoEstado::Castigado, $credito->fresh()->estado);
        $this->assertTrue(app(LimitesService::class)->evaluar($cliente->id, 10_000)->infraccion(ReglaLimite::Bloqueado)?->bloquea);

        $moraAlCastigo = $this->mora($credito, 1, '2026-05-02');
        $this->assertSame($moraAlCastigo, $this->mora($credito, 1, '2026-06-01'));   // congelada

        $this->hoy('2026-06-01');
        app(CastigoService::class)->revertir($credito, 'Cliente regularizó', $this->usuario);
        // Tras revertir corre desde el 01/06: al 03/06, 2 días × 40.
        $this->assertSame($moraAlCastigo + 8_000, $this->mora($credito, 1, '2026-06-03'));
    }

    public function test_pago_total_de_un_castigado_es_recupero(): void
    {
        $cliente = $this->cliente();
        $credito = $this->activo($this->usuario, $cliente, $this->datos($cliente->id, capital: 100_000, cuotas: 1));
        $this->hoy('2026-03-01');
        app(CastigoService::class)->castigar($credito, TipoCastigo::Manual, 'Incobrable', $this->usuario);

        $this->cobrar($credito, '2026-03-02', 240_000);   // cuota + mora topada al 100% (1,200)

        $credito->refresh();
        $this->assertSame(CreditoEstado::Finalizado, $credito->estado);
        $this->assertSame(MotivoCierre::RecuperoCastigo, $credito->motivo_cierre);
    }

    // ---- Renovación ----

    public function test_renovacion_con_liquidacion_300_entrega_700_y_cierra_el_anterior_sin_caja(): void
    {
        $cliente = $this->cliente();
        // 1,000 al 20% en 10 cuotas de 120; pagó 7 → liquidación 300 el día del 7.º vencimiento.
        $anterior = $this->activo($this->usuario, $cliente, $this->datos($cliente->id, capital: 100_000));
        $fechas = $anterior->cuotasVigentes()->get()->pluck('fecha_vencimiento')->map->format('Y-m-d');
        for ($i = 0; $i < 7; $i++) {
            $this->cobrar($anterior, $fechas[$i], 12_000);
        }
        $cajaAntes = $this->saldoCaja($this->sesion->id);
        $this->hoy($fechas[6]);

        $nuevo = app(RenovacionService::class)->renovar($anterior, $this->datos($cliente->id, capital: 100_000, desembolso: $fechas[6]), null, $this->usuario);

        $anterior->refresh();
        $this->assertSame(CreditoEstado::Finalizado, $anterior->estado);
        $this->assertSame(MotivoCierre::Renovacion, $anterior->motivo_cierre);
        $this->assertSame(OrigenRegistro::Renovacion, $nuevo->origen_registro);
        $this->assertSame($anterior->id, $nuevo->credito_renovado_id);
        $this->assertSame(number_format((float) $cajaAntes - 700, 2, '.', ''), $this->saldoCaja($this->sesion->id));
        $this->assertNull(CreditoPago::where('credito_id', $anterior->id)->where('origen', 'renovacion')->value('cash_movement_id'));
    }

    public function test_anular_el_credito_de_renovacion_reabre_el_anterior(): void
    {
        $cliente = $this->cliente();
        $anterior = $this->activo($this->usuario, $cliente, $this->datos($cliente->id, capital: 100_000));
        $this->hoy('2026-01-10');
        $nuevo = app(RenovacionService::class)->renovar($anterior, $this->datos($cliente->id, capital: 200_000, desembolso: '2026-01-10'), null, $this->usuario);

        $pagoRenovacion = CreditoPago::where('credito_id', $anterior->id)->where('origen', 'renovacion')->firstOrFail();
        try {
            app(AnulacionPagoService::class)->anular($pagoRenovacion, 'x', $this->usuario);
            $this->fail('El pago de renovación se deshace anulando el crédito nuevo.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        app(CorreccionService::class)->anular($nuevo, 'Renovación por error', $this->usuario);

        $this->assertSame(CreditoEstado::Activo, $anterior->fresh()->estado);
        $this->assertSame(PagoEstado::Anulado, $pagoRenovacion->fresh()->estado);
        $this->assertSame('-1000.00', $this->saldoCaja($this->sesion->id));   // solo el desembolso original
    }

    // ---- Migración ----

    public function test_migracion_modo_rapido_sin_caja_y_limites_solo_advierten(): void
    {
        $cliente = $this->cliente();
        $this->activo($this->usuario, $cliente);
        $this->activo($this->usuario, $cliente);
        $this->hoy('2026-04-10');
        $cajaAntes = $this->saldoCaja($this->sesion->id);

        $this->assertFalse(app(LimitesService::class)->evaluar($cliente->id, 500_000, esMigracion: true)->bloquea());
        $credito = app(MigracionService::class)->migrar($this->datos($cliente->id), 3, [], false, 'm-1', $this->usuario);

        $this->assertSame(OrigenRegistro::Migracion, $credito->origen_registro);
        $this->assertSame(3, $credito->cuotasVigentes()->where('estado', EstadoCuota::Pagada)->count());
        $this->assertSame(3, $credito->pagos()->where('origen', 'saldo_inicial')->whereNull('cash_movement_id')->count());
        $this->assertSame($cajaAntes, $this->saldoCaja($this->sesion->id));
    }

    public function test_migracion_modo_detallado_calcula_y_condona_la_mora_historica(): void
    {
        $cliente = $this->cliente();
        $this->hoy('2026-03-01');
        // Cuota 1 (31/01) pagada 10 días tarde con 800: 600 + 200 de mora. Cuota 2 aún no vence.
        $pagos = [new PagoHistorico(Fecha::desdeTexto('2026-02-10'), 80_000)];

        $credito = app(MigracionService::class)->migrar($this->datos($cliente->id), null, $pagos, false, 'm-2', $this->usuario);

        $cuota1 = $credito->cuotasVigentes()->first();
        $this->assertSame('200.00', $cuota1->mora_pagada);
        $this->assertSame(10, $cuota1->dias_atraso_al_pagar);
    }

    public function test_migracion_con_mora_pendiente_condonada(): void
    {
        $cliente = $this->cliente();
        $this->hoy('2026-02-10');

        $credito = app(MigracionService::class)->migrar($this->datos($cliente->id), null, [new PagoHistorico(Fecha::desdeTexto('2026-02-10'), 60_000)], true, 'm-3', $this->usuario);

        $this->assertSame(0, $this->mora($credito, 1, '2026-02-10'));
        $this->assertSame('200.00', $credito->cuotasVigentes()->first()->mora_condonada);
    }
}
