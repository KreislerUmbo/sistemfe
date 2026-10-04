<?php

declare(strict_types=1);

namespace App\Services\Creditos\Reportes;

use App\Enums\Creditos\CondonacionEstado;
use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\OrigenRegistro;
use App\Enums\Creditos\PagoEstado;
use App\Enums\Creditos\TipoMovimientoSaldoFavor;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoCastigo;
use App\Models\Creditos\CreditoCondonacion;
use App\Models\Creditos\CreditoCuota;
use App\Models\Creditos\CreditoPago;
use App\Models\Creditos\CreditoSaldoFavorMovimiento;
use App\Models\User;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Fecha;
use Illuminate\Support\Facades\DB;

/**
 * Ingresos y desembolsos, Por asesor, y Castigados y recuperos (04d). Solo para quien ve toda la
 * cartera (lo exige el controller). Base caja: lo cobrado, no lo devengado. Los pagos anteriores
 * al sistema (saldo_inicial, al migrar) no son ingresos del período. Montos en centavos.
 */
class ReportesFinancierosService
{
    public const AGRUPACIONES = ['dia', 'semana', 'mes'];

    public function __construct(private readonly CarteraEnVivo $cartera)
    {
    }

    /** @return array<string, mixed> */
    public function ingresos(Periodo $periodo, string $agrupacion): array
    {
        $vacio = static fn (): array => [
            'desembolsado' => 0, 'creditos_entregados' => 0, 'capital' => 0, 'interes' => 0, 'mora' => 0, 'cargo' => 0,
            'mora_condonada' => 0, 'interes_descontado' => 0, 'saldo_favor_devuelto' => 0,
        ];
        $grupos = [];
        $sumar = function (string $fecha, string $campo, int $monto) use (&$grupos, $agrupacion, $vacio): void {
            $clave = self::clave(Fecha::desdeTexto($fecha), $agrupacion);
            $grupos[$clave] ??= $vacio();
            $grupos[$clave][$campo] += $monto;
        };

        // Desembolsos: créditos entregados en el período (los migrados se entregaron antes del sistema).
        Credito::whereNotIn('estado', [CreditoEstado::Borrador, CreditoEstado::Anulado])
            ->where('origen_registro', '!=', OrigenRegistro::Migracion)
            ->whereBetween('fecha_desembolso', [$periodo->desde->aTexto(), $periodo->hasta->aTexto()])
            ->get(['fecha_desembolso', 'monto_capital'])
            ->each(function (Credito $c) use ($sumar): void {
                $sumar($c->fecha_desembolso->format('Y-m-d'), 'desembolsado', Dinero::aCentavos($c->monto_capital));
                $sumar($c->fecha_desembolso->format('Y-m-d'), 'creditos_entregados', 1);
            });

        // Cobrado por concepto: aplicaciones vigentes de pagos válidos, en la fecha del pago.
        DB::table('credito_pago_aplicaciones as a')
            ->join('credito_pagos as p', 'p.id', '=', 'a.pago_id')
            ->where('a.vigente', true)->where('p.estado', PagoEstado::Valido->value)
            ->where('p.origen', '!=', OrigenPago::SaldoInicial->value)
            ->where('p.fecha_pago', '>=', $periodo->desde->aTexto())->where('p.fecha_pago', '<', $periodo->hastaExclusivo())
            ->groupBy(DB::raw('date(p.fecha_pago)'), 'a.concepto')
            ->selectRaw('date(p.fecha_pago) as dia, a.concepto, sum(a.monto) as total')
            ->get()
            ->each(fn ($f) => $sumar((string) $f->dia, (string) $f->concepto, Dinero::aCentavos((string) $f->total)));

        CreditoCondonacion::where('estado', CondonacionEstado::Vigente)
            ->where('created_at', '>=', $periodo->desdeUtc())->where('created_at', '<', $periodo->hastaUtc())
            ->get(['created_at', 'monto'])
            ->each(fn (CreditoCondonacion $c) => $sumar(self::diaLima($c->created_at), 'mora_condonada', Dinero::aCentavos($c->monto)));

        // Interés descontado al cancelar antes (liquidación o renovación, ambas cierran el crédito):
        // interes_condonado de las cuotas del crédito cerrado (00 1.7).
        CreditoPago::where('estado', PagoEstado::Valido)->where('es_cierre', true)
            ->whereIn('origen', [OrigenPago::Liquidacion, OrigenPago::Renovacion])
            ->where('fecha_pago', '>=', $periodo->desde->aTexto())->where('fecha_pago', '<', $periodo->hastaExclusivo())
            ->with('credito:id,version_cronograma_actual')
            ->get(['id', 'credito_id', 'fecha_pago'])
            ->each(function (CreditoPago $p) use ($sumar): void {
                $descontado = CreditoCuota::where('credito_id', $p->credito_id)
                    ->where('version_cronograma', $p->credito->version_cronograma_actual)->sum('interes_condonado');
                $sumar($p->fecha_pago->format('Y-m-d'), 'interes_descontado', Dinero::aCentavos((string) $descontado));
            });

        CreditoSaldoFavorMovimiento::where('tipo', TipoMovimientoSaldoFavor::Devolucion)
            ->where('created_at', '>=', $periodo->desdeUtc())->where('created_at', '<', $periodo->hastaUtc())
            ->get(['created_at', 'monto'])
            ->each(fn (CreditoSaldoFavorMovimiento $m) => $sumar(self::diaLima($m->created_at), 'saldo_favor_devuelto', -Dinero::aCentavos($m->monto)));

        ksort($grupos);
        $total = $vacio();
        foreach ($grupos as $g) {
            foreach ($g as $campo => $monto) {
                $total[$campo] += $monto;
            }
        }
        $formato = static function (array $g): array {
            $f = array_map(static fn (int $v): string => Dinero::aSoles($v), $g);
            $f['creditos_entregados'] = $g['creditos_entregados'];
            $f['cobrado'] = Dinero::aSoles($g['capital'] + $g['interes'] + $g['mora'] + $g['cargo']);

            return $f;
        };

        return [
            'periodo' => ['desde' => $periodo->desde->aTexto(), 'hasta' => $periodo->hasta->aTexto(), 'texto' => $periodo->texto()],
            'agrupacion' => $agrupacion,
            'filas' => array_map(static fn (string $clave, array $g): array => ['periodo' => $clave, ...$formato($g)], array_keys($grupos), $grupos),
            'totales' => $formato($total),
        ];
    }

    /**
     * Por asesor: lo que colocó en el período (creditos.asesor_id, fijado al activar) y lo cobrado de
     * esos créditos; y su cartera de hoy (clientes asignados). Base para comisiones, no las calcula.
     *
     * @return array<string, mixed>
     */
    public function porAsesor(Periodo $periodo, User $usuario, ReportesCarteraService $carteraService): array
    {
        $filas = [];
        $fila = function (?int $id, ?string $nombre) use (&$filas): string {
            $clave = (string) ($id ?? 0);
            $filas[$clave] ??= ['asesor_id' => $id, 'asesor' => $nombre ?? 'Sin asesor', 'colocados' => 0, 'capital_colocado' => 0,
                'cobrado' => 0, 'cartera_creditos' => 0, 'cartera_saldo' => 0, 'cartera_riesgo' => 0];

            return $clave;
        };
        $nombres = User::withTrashed()->pluck('name', 'id');

        Credito::whereNotIn('estado', [CreditoEstado::Borrador, CreditoEstado::Anulado])
            ->where('origen_registro', '!=', OrigenRegistro::Migracion)
            ->whereBetween('fecha_desembolso', [$periodo->desde->aTexto(), $periodo->hasta->aTexto()])
            ->get(['asesor_id', 'monto_capital'])
            ->each(function (Credito $c) use (&$filas, $fila, $nombres): void {
                $k = $fila($c->asesor_id, $nombres[$c->asesor_id] ?? null);
                $filas[$k]['colocados']++;
                $filas[$k]['capital_colocado'] += Dinero::aCentavos($c->monto_capital);
            });

        DB::table('credito_pagos as p')->join('creditos as c', 'c.id', '=', 'p.credito_id')
            ->where('p.estado', PagoEstado::Valido->value)->where('p.origen', '!=', OrigenPago::SaldoInicial->value)
            ->where('p.fecha_pago', '>=', $periodo->desde->aTexto())->where('p.fecha_pago', '<', $periodo->hastaExclusivo())
            ->groupBy('c.asesor_id')->selectRaw('c.asesor_id, sum(p.monto_aplicado) as total')->get()
            ->each(function ($f) use (&$filas, $fila, $nombres): void {
                $id = $f->asesor_id === null ? null : (int) $f->asesor_id;
                $filas[$fila($id, $nombres[$id] ?? null)]['cobrado'] += Dinero::aCentavos((string) $f->total);
            });

        // Cartera de hoy por asesor vigente del cliente (quién lo atiende hoy).
        $situaciones = $carteraService->situaciones($usuario, [CreditoEstado::Activo]);
        $asignaciones = app(AsignacionesVigentes::class)->de(array_map(static fn (SituacionCartera $s): int => $s->credito->cliente_id, $situaciones));
        foreach ($situaciones as $s) {
            $a = $asignaciones[$s->credito->cliente_id];
            $k = $fila($a['asesor_id'], $a['asesor']);
            $filas[$k]['cartera_creditos']++;
            $filas[$k]['cartera_saldo'] += $s->saldoCapital;
            if ($s->enRiesgo()) {
                $filas[$k]['cartera_riesgo'] += $s->saldoCapital;
            }
        }

        $resultado = array_values(array_map(static fn (array $f): array => [
            'asesor_id' => $f['asesor_id'],
            'asesor' => $f['asesor'],
            'colocados' => $f['colocados'],
            'capital_colocado' => Dinero::aSoles($f['capital_colocado']),
            'cobrado' => Dinero::aSoles($f['cobrado']),
            'cartera_creditos' => $f['cartera_creditos'],
            'cartera_saldo' => Dinero::aSoles($f['cartera_saldo']),
            'porcentaje_riesgo' => ReportesCarteraService::porcentaje($f['cartera_riesgo'], $f['cartera_saldo']),
        ], $filas));
        usort($resultado, static fn (array $x, array $y): int => strcmp($x['asesor'], $y['asesor']));

        return [
            'periodo' => ['desde' => $periodo->desde->aTexto(), 'hasta' => $periodo->hasta->aTexto(), 'texto' => $periodo->texto()],
            'filas' => $resultado,
        ];
    }

    /** @return array<string, mixed> castigos del período y recuperos (pagos durante un castigo vigente) */
    public function castigados(Periodo $periodo): array
    {
        $castigos = CreditoCastigo::with('credito.cliente')
            ->whereBetween('fecha_castigo', [$periodo->desde->aTexto(), $periodo->hasta->aTexto()])
            ->orderBy('fecha_castigo')->get();
        $saldos = [];
        $ids = $castigos->pluck('credito_id')->unique();
        if ($ids->isNotEmpty()) {
            foreach ($this->cartera->calcular(Credito::query()->whereKey($ids), app(\App\Services\Creditos\Reloj::class)->hoy()) as $s) {
                $saldos[$s->credito->id] = $s->saldoCapital + $s->saldoInteres;
            }
        }

        $recuperos = DB::table('credito_pagos as p')
            ->join('credito_castigos as k', 'k.credito_id', '=', 'p.credito_id')
            ->join('creditos as c', 'c.id', '=', 'p.credito_id')
            ->leftJoin('clients as cl', 'cl.id', '=', 'c.cliente_id')
            ->where('p.estado', PagoEstado::Valido->value)
            ->where('p.fecha_pago', '>=', $periodo->desde->aTexto())->where('p.fecha_pago', '<', $periodo->hastaExclusivo())
            ->whereRaw('date(p.fecha_pago) >= k.fecha_castigo')
            ->where(static fn ($q) => $q->whereNull('k.fecha_reversion')->orWhereRaw('date(p.fecha_pago) < k.fecha_reversion'))
            ->orderBy('p.fecha_pago')
            ->get(['p.id', 'p.numero_recibo', 'p.fecha_pago', 'p.monto_aplicado', 'c.id as credito_id', 'c.numero_credito', 'cl.full_name as cliente']);

        return [
            'periodo' => ['desde' => $periodo->desde->aTexto(), 'hasta' => $periodo->hasta->aTexto(), 'texto' => $periodo->texto()],
            'castigos' => $castigos->map(static fn (CreditoCastigo $k): array => [
                'credito_id' => $k->credito_id,
                'numero_credito' => $k->credito?->numero_credito,
                'cliente' => $k->credito?->cliente?->full_name,
                'fecha_castigo' => $k->fecha_castigo->format('Y-m-d'),
                'tipo' => $k->tipo->value,
                'revertido' => $k->fecha_reversion !== null,
                'saldo_hoy' => Dinero::aSoles($saldos[$k->credito_id] ?? 0),
                'motivo' => $k->motivo,
            ])->values()->all(),
            'recuperos' => $recuperos->map(static fn ($p): array => [
                'pago_id' => $p->id,
                'numero_recibo' => $p->numero_recibo,
                'fecha' => substr((string) $p->fecha_pago, 0, 10),
                'credito_id' => $p->credito_id,
                'numero_credito' => $p->numero_credito,
                'cliente' => $p->cliente,
                'monto' => Dinero::aSoles(Dinero::aCentavos((string) $p->monto_aplicado)),
            ])->values()->all(),
            'total_recuperado' => Dinero::aSoles($recuperos->sum(static fn ($p): int => Dinero::aCentavos((string) $p->monto_aplicado))),
        ];
    }

    /** Clave del grupo: el día, el lunes de la semana o el mes (texto ordenable). */
    public static function clave(Fecha $dia, string $agrupacion): string
    {
        return match ($agrupacion) {
            'semana' => $dia->sumarDias(1 - $dia->diaIso())->aTexto(),
            'mes' => substr($dia->aTexto(), 0, 7),
            default => $dia->aTexto(),
        };
    }

    private static function diaLima(\DateTimeInterface $momento): string
    {
        return \Carbon\CarbonImmutable::instance($momento)->setTimezone(\App\Services\Creditos\Reloj::ZONA)->format('Y-m-d');
    }
}
