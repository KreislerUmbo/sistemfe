<?php

declare(strict_types=1);

namespace App\Services\Creditos\Reportes;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\PagoEstado;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\CajaCredito;
use App\Services\Creditos\ConsultaCreditoService;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\CobroDelDia;
use App\Services\Creditos\Dto\DetalleCredito;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Reloj;
use Illuminate\Support\Facades\DB;

/**
 * Panel de inicio, Cartera y Morosidad (04d). Saldos, mora y atraso salen de CarteraEnVivo (el
 * motor); "por cobrar" y "cobrado" de hoy, de las mismas funciones que la Cobranza del día.
 * Montos en centavos; los porcentajes con un decimal.
 */
class ReportesCarteraService
{
    private const DIAS_GRAFICO = 14;
    private const MAS_ATRASADOS = 10;
    /** Cobros que entran a caja (la renovación y el saldo a favor no mueven dinero). */
    private const ORIGENES_CAJA = [OrigenPago::Cobro, OrigenPago::Liquidacion];

    public function __construct(
        private readonly CarteraEnVivo $cartera,
        private readonly AlcanceCartera $alcance,
        private readonly AsignacionesVigentes $asignaciones,
        private readonly ConsultaCreditoService $consultas,
        private readonly Reloj $reloj,
    ) {
    }

    /** @return array<string, mixed> */
    public function panel(User $usuario): array
    {
        $hoy = $this->reloj->hoy();
        $situaciones = $this->situaciones($usuario, [CreditoEstado::Activo]);
        $cartera = array_sum(array_map(static fn (SituacionCartera $s): int => $s->saldoCapital, $situaciones));
        $enRiesgo = array_filter($situaciones, static fn (SituacionCartera $s): bool => $s->enRiesgo());
        $riesgo = array_sum(array_map(static fn (SituacionCartera $s): int => $s->saldoCapital, $enRiesgo));

        $porCobrar = array_sum(array_map(static fn (DetalleCredito $d): int => $d->exigible, $this->consultas->cobranzaDelDia($usuario)));
        $cobrado = array_sum(array_map(static fn (CobroDelDia $c): int => $c->montoAplicado, $this->consultas->cobradosHoy($usuario)));

        usort($situaciones, static fn (SituacionCartera $a, SituacionCartera $b): int => [$b->diasAtraso, $b->saldoCapital] <=> [$a->diasAtraso, $a->saldoCapital]);
        $atrasados = array_slice(array_values(array_filter($situaciones, static fn (SituacionCartera $s): bool => $s->diasAtraso > 0)), 0, self::MAS_ATRASADOS);

        return [
            'corte' => $hoy->aTexto(),
            'cartera' => [
                'saldo_capital' => Dinero::aSoles($cartera),
                'creditos' => count($situaciones),
                'clientes' => count(array_unique(array_map(static fn (SituacionCartera $s): int => $s->credito->cliente_id, $situaciones))),
            ],
            'riesgo' => [
                'porcentaje' => self::porcentaje($riesgo, $cartera),
                'saldo_capital' => Dinero::aSoles($riesgo),
                'creditos' => count($enRiesgo),
            ],
            'cobranza_hoy' => [
                'por_cobrar' => Dinero::aSoles($porCobrar),
                'cobrado' => Dinero::aSoles($cobrado),
                'porcentaje' => self::porcentaje($cobrado, $porCobrar + $cobrado),
            ],
            'conciliacion' => $usuario->can(AlcanceCartera::PERMISO_VER_TODOS) ? $this->conciliacion(Periodo::dia($hoy)) : null,
            'cobrado_dias' => $this->cobradoPorDia($usuario, Periodo::entre($hoy->sumarDias(-(self::DIAS_GRAFICO - 1)), $hoy)),
            'mas_atrasados' => array_map(fn (SituacionCartera $s): array => $this->filaCorta($s), $atrasados),
        ];
    }

    /**
     * @param array{asesor_id?: int|null, rango?: string|null, estado?: string|null} $filtros asesor_id 0 = sin asesor
     * @return array<string, mixed>
     */
    public function cartera(User $usuario, array $filtros): array
    {
        $estados = match ($filtros['estado'] ?? null) {
            'activo' => [CreditoEstado::Activo],
            'castigado' => [CreditoEstado::Castigado],
            default => CarteraEnVivo::ESTADOS,
        };
        $situaciones = $this->situaciones($usuario, $estados);
        $asignaciones = $this->asignaciones->de(array_map(static fn (SituacionCartera $s): int => $s->credito->cliente_id, $situaciones));

        $filas = [];
        foreach ($situaciones as $s) {
            $a = $asignaciones[$s->credito->cliente_id];
            if (array_key_exists('asesor_id', $filtros) && $filtros['asesor_id'] !== null && (int) ($a['asesor_id'] ?? 0) !== $filtros['asesor_id']) {
                continue;
            }
            if (! empty($filtros['rango']) && $s->rangoAtraso() !== $filtros['rango']) {
                continue;
            }
            $filas[] = [
                ...$this->filaCorta($s),
                'estado' => $s->credito->estado->value,
                'asesor' => $a['asesor'],
                'capital_prestado' => (string) $s->credito->monto_capital,
                'saldo_interes' => Dinero::aSoles($s->saldoInteres),
                'cuotas_pagadas' => $s->cuotasPagadas,
                'cuotas_total' => $s->cuotasTotal,
                'proximo_vencimiento' => $s->proxima?->fechaVencimiento->aTexto(),
                'rango_atraso' => $s->rangoAtraso(),
            ];
        }
        usort($filas, static fn (array $x, array $y): int => [$y['dias_atraso'], $x['cliente']] <=> [$x['dias_atraso'], $y['cliente']]);

        return [
            'corte' => $this->reloj->hoy()->aTexto(),
            'filas' => $filas,
            'totales' => [
                'creditos' => count($filas),
                'capital_prestado' => Dinero::aSoles(array_sum(array_map(static fn (array $f): int => Dinero::aCentavos($f['capital_prestado']), $filas))),
                'saldo_capital' => Dinero::aSoles(array_sum(array_map(static fn (array $f): int => Dinero::aCentavos($f['saldo_capital']), $filas))),
                'saldo_interes' => Dinero::aSoles(array_sum(array_map(static fn (array $f): int => Dinero::aCentavos($f['saldo_interes']), $filas))),
                'mora' => Dinero::aSoles(array_sum(array_map(static fn (array $f): int => Dinero::aCentavos($f['mora']), $filas))),
            ],
        ];
    }

    /** @return array<string, mixed> rangos de atraso (cantidad y saldo), total y por asesor */
    public function morosidad(User $usuario): array
    {
        $situaciones = $this->situaciones($usuario, [CreditoEstado::Activo]);
        $asignaciones = $this->asignaciones->de(array_map(static fn (SituacionCartera $s): int => $s->credito->cliente_id, $situaciones));
        $rangos = ['al_dia', '1-7', '8-15', '16-30', '31-60', '60+'];
        $vacio = static fn (): array => array_fill_keys($rangos, ['creditos' => 0, 'saldo' => 0]) + ['_riesgo' => 0, '_total' => 0];

        $total = $vacio();
        $porAsesor = [];
        foreach ($situaciones as $s) {
            $nombre = $asignaciones[$s->credito->cliente_id]['asesor'] ?? 'Sin asesor';
            $porAsesor[$nombre] ??= $vacio();
            foreach ([&$total, &$porAsesor[$nombre]] as &$grupo) {
                $grupo[$s->rangoAtraso()]['creditos']++;
                $grupo[$s->rangoAtraso()]['saldo'] += $s->saldoCapital;
                $grupo['_total'] += $s->saldoCapital;
                if ($s->enRiesgo()) {
                    $grupo['_riesgo'] += $s->saldoCapital;
                }
            }
            unset($grupo);
        }
        ksort($porAsesor);

        $formato = static fn (array $g): array => [
            'rangos' => array_map(static fn (string $r): array => ['rango' => $r, 'creditos' => $g[$r]['creditos'], 'saldo' => Dinero::aSoles($g[$r]['saldo'])], $rangos),
            'saldo_total' => Dinero::aSoles($g['_total']),
            'saldo_riesgo' => Dinero::aSoles($g['_riesgo']),
            'porcentaje_riesgo' => self::porcentaje($g['_riesgo'], $g['_total']),
        ];

        return [
            'corte' => $this->reloj->hoy()->aTexto(),
            'total' => $formato($total),
            'por_asesor' => array_map(static fn (string $nombre, array $g): array => ['asesor' => $nombre, ...$formato($g)], array_keys($porAsesor), $porAsesor),
        ];
    }

    /**
     * Cobrado en créditos = entradas de caja de créditos − vueltos, el mismo día (04d). Compara por
     * fecha de REGISTRO (created_at): un pago retroactivo entra a la caja el día que se registra.
     *
     * @return array{creditos: string, caja: string, cuadra: bool, diferencia: string}
     */
    public function conciliacion(Periodo $periodo): array
    {
        $pagos = CreditoPago::where('estado', PagoEstado::Valido)
            ->whereIn('origen', self::ORIGENES_CAJA)
            ->whereNotNull('payment_method_id')
            ->where('created_at', '>=', $periodo->desdeUtc())->where('created_at', '<', $periodo->hastaUtc())
            ->get(['monto_recibido', 'monto_excedente', 'destino_excedente']);
        $creditos = $pagos->sum(static fn (CreditoPago $p): int => Dinero::aCentavos($p->monto_recibido)
            - ($p->destino_excedente?->value === 'devolver' ? Dinero::aCentavos($p->monto_excedente) : 0));

        $caja = DB::table('cash_movements')
            ->whereIn('reference_type', [CajaCredito::PAGO, CajaCredito::DEVOLUCION_EXCEDENTE])
            ->where('status', 'confirmed')->whereNull('corrected_by')
            ->where('created_at', '>=', $periodo->desdeUtc())->where('created_at', '<', $periodo->hastaUtc())
            ->get(['direction', 'amount'])
            ->sum(static fn ($m): int => ($m->direction === 'in' ? 1 : -1) * Dinero::aCentavos((string) $m->amount));

        return [
            'creditos' => Dinero::aSoles($creditos),
            'caja' => Dinero::aSoles($caja),
            'cuadra' => $creditos === $caja,
            'diferencia' => Dinero::aSoles($caja - $creditos),
        ];
    }

    /** @return list<array{fecha: string, monto: string}> monto aplicado por día (cobros y liquidaciones) */
    public function cobradoPorDia(User $usuario, Periodo $periodo): array
    {
        $totales = CreditoPago::where('estado', PagoEstado::Valido)
            ->whereIn('origen', self::ORIGENES_CAJA)
            ->where('fecha_pago', '>=', $periodo->desde->aTexto())->where('fecha_pago', '<', $periodo->hastaExclusivo())
            ->whereHas('credito', fn ($q) => $this->alcance->aplicar($q, $usuario))
            ->get(['fecha_pago', 'monto_aplicado'])
            ->groupBy(static fn (CreditoPago $p): string => $p->fecha_pago->format('Y-m-d'))
            ->map(static fn ($pagos): int => $pagos->sum(static fn (CreditoPago $p): int => Dinero::aCentavos($p->monto_aplicado)));

        return array_map(static fn (Fecha $d): array => ['fecha' => $d->aTexto(), 'monto' => Dinero::aSoles($totales[$d->aTexto()] ?? 0)], $periodo->dias());
    }

    /**
     * @param list<CreditoEstado> $estados
     * @return list<SituacionCartera>
     */
    public function situaciones(User $usuario, array $estados): array
    {
        return $this->cartera->calcular(
            $this->alcance->aplicar(Credito::query(), $usuario)->whereIn('estado', $estados),
            $this->reloj->hoy(),
        );
    }

    /** @return array<string, mixed> */
    private function filaCorta(SituacionCartera $s): array
    {
        return [
            'credito_id' => $s->credito->id,
            'numero_credito' => $s->credito->numero_credito,
            'cliente' => $s->credito->cliente?->full_name,
            'cliente_id' => $s->credito->cliente_id,
            'dias_atraso' => $s->diasAtraso,
            'saldo_capital' => Dinero::aSoles($s->saldoCapital),
            'mora' => Dinero::aSoles($s->mora),
        ];
    }

    /** Porcentaje con un decimal como texto ("8.4"); 0 si no hay base. Enteros: sin coma flotante. */
    public static function porcentaje(int $parte, int $total): string
    {
        if ($total <= 0) {
            return '0.0';
        }
        $decimas = intdiv($parte * 1000 + intdiv($total, 2), $total);

        return intdiv($decimas, 10) . '.' . ($decimas % 10);
    }
}
