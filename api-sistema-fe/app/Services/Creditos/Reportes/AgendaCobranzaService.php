<?php

declare(strict_types=1);

namespace App\Services\Creditos\Reportes;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\FuncionCartera;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoClienteFicha;
use App\Models\Creditos\CreditoCuota;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Documentos\FormatoDocumento;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Reloj;

/**
 * Agenda de cobranza (04d, reporte 8): quiénes vencen en un día o rango, para la logística de
 * cobro. Mira hacia adelante (la Cobranza del día solo ve hoy y lo atrasado). Agrupada por día y
 * por cobrador. El monto de cada cuota es lo pendiente según los acumulados que el motor deja
 * escritos en la cuota (se reescriben en cada cobro/anulación); una cuota por vencer no tiene mora.
 * El cobrador ve solo los clientes que cobra.
 */
class AgendaCobranzaService
{
    public const MAXIMO_DIAS = 31;

    public function __construct(
        private readonly AlcanceCartera $alcance,
        private readonly AsignacionesVigentes $asignaciones,
        private readonly CarteraEnVivo $cartera,
        private readonly Reloj $reloj,
    ) {
    }

    /**
     * @param array{cobrador_id?: int|null, distrito?: string|null} $filtros cobrador_id 0 = sin cobrador
     * @return array<string, mixed>
     */
    public function agenda(User $usuario, Periodo $periodo, bool $incluirAtrasados, array $filtros = []): array
    {
        $hoy = $this->reloj->hoy();
        $creditos = $this->alcance->aplicar(Credito::query(), $usuario, FuncionCartera::Cobrador)
            ->where('estado', CreditoEstado::Activo)->select('id');
        $cuotas = CreditoCuota::with(['credito.cliente'])
            ->whereIn('credito_id', $creditos)
            ->whereRaw('version_cronograma = (select c.version_cronograma_actual from creditos c where c.id = credito_cuotas.credito_id)')
            ->where('estado', EstadoCuota::Pendiente)
            ->where(function ($q) use ($periodo, $incluirAtrasados, $hoy): void {
                $q->whereBetween('fecha_vencimiento', [$periodo->desde->aTexto(), $periodo->hasta->aTexto()]);
                if ($incluirAtrasados) {
                    $q->orWhere('fecha_vencimiento', '<', $hoy->aTexto());
                }
            })
            ->orderBy('fecha_vencimiento')->orderBy('credito_id')
            ->get();

        $creditoIds = $cuotas->pluck('credito_id')->unique()->values()->all();
        $clienteIds = $cuotas->map(static fn (CreditoCuota $c): int => $c->credito->cliente_id)->unique()->values()->all();
        $asignaciones = $this->asignaciones->de($clienteIds);
        $fichas = CreditoClienteFicha::whereIn('cliente_id', $clienteIds)->get()->keyBy('cliente_id');
        $totalCuotas = CreditoCuota::whereIn('credito_id', $creditoIds)
            ->whereRaw('version_cronograma = (select c.version_cronograma_actual from creditos c where c.id = credito_cuotas.credito_id)')
            ->groupBy('credito_id')->selectRaw('credito_id, count(*) as total')->pluck('total', 'credito_id');
        $conAtraso = CreditoCuota::whereIn('credito_id', $creditoIds)
            ->whereRaw('version_cronograma = (select c.version_cronograma_actual from creditos c where c.id = credito_cuotas.credito_id)')
            ->where('estado', EstadoCuota::Pendiente)->where('fecha_vencimiento', '<', $hoy->aTexto())
            ->distinct()->pluck('credito_id')->flip();
        // Mora solo de lo atrasado (lo que vence en el rango todavía no tiene): misma cifra del motor.
        $moras = [];
        if ($incluirAtrasados && $conAtraso->isNotEmpty()) {
            foreach ($this->cartera->calcular(Credito::query()->whereKey($conAtraso->keys()), $hoy) as $s) {
                $moras[$s->credito->id] = $s->mora;
            }
        }

        $filas = [];
        $moraYaMostrada = [];
        foreach ($cuotas as $c) {
            $credito = $c->credito;
            $cliente = $credito->cliente;
            $a = $asignaciones[$credito->cliente_id];
            $cobradorId = $a['cobrador_id'] ?? $a['asesor_id'];
            $cobrador = $a['cobrador'] ?? $a['asesor'] ?? 'Sin cobrador';
            if (array_key_exists('cobrador_id', $filtros) && $filtros['cobrador_id'] !== null && (int) ($cobradorId ?? 0) !== $filtros['cobrador_id']) {
                continue;
            }
            if (! empty($filtros['distrito']) && ($cliente?->distrito ?? '') !== $filtros['distrito']) {
                continue;
            }
            $pendiente = (Dinero::aCentavos($c->monto_capital) - Dinero::aCentavos($c->capital_pagado))
                + (Dinero::aCentavos($c->monto_interes) - Dinero::aCentavos($c->interes_pagado) - Dinero::aCentavos($c->interes_condonado))
                + (Dinero::aCentavos($c->cargo_monto) - Dinero::aCentavos($c->cargo_pagado));
            if ($pendiente <= 0) {
                continue;
            }
            $atrasada = $c->fecha_vencimiento->format('Y-m-d') < $hoy->aTexto();
            $mora = $atrasada && ! isset($moraYaMostrada[$credito->id]) ? ($moras[$credito->id] ?? 0) : 0;
            $moraYaMostrada[$credito->id] = true;
            $ficha = $fichas[$credito->cliente_id] ?? null;

            $filas[] = [
                'dia' => $atrasada ? null : $c->fecha_vencimiento->format('Y-m-d'),
                'fecha_vencimiento' => $c->fecha_vencimiento->format('Y-m-d'),
                'cobrador_id' => $cobradorId,
                'cobrador' => $cobrador,
                'credito_id' => $credito->id,
                'numero_credito' => $credito->numero_credito,
                'forma_pago' => FormatoDocumento::frecuencia($credito),
                'numero_cuota' => $c->numero_cuota,
                'cuotas_total' => (int) ($totalCuotas[$credito->id] ?? 0),
                'pendiente' => Dinero::aSoles($pendiente),
                'mora' => Dinero::aSoles($mora),
                'con_atraso' => $conAtraso->has($credito->id),
                'cliente' => [
                    'id' => $cliente?->id,
                    'nombre' => $cliente?->full_name,
                    'telefono' => $cliente?->phone,
                    'telefono_alterno' => $ficha?->telefono_alterno,
                    'direccion_cobro' => $ficha?->direccion_cobro ?: $cliente?->address,
                    'referencia' => $ficha?->referencia,
                    'distrito' => $cliente?->distrito,
                    'latitud' => $ficha?->latitud !== null ? (string) $ficha->latitud : null,
                    'longitud' => $ficha?->longitud !== null ? (string) $ficha->longitud : null,
                ],
            ];
        }

        return [
            'periodo' => ['desde' => $periodo->desde->aTexto(), 'hasta' => $periodo->hasta->aTexto(), 'texto' => $periodo->texto()],
            'hoy' => $hoy->aTexto(),
            'dias' => $this->agrupar($filas),
            'totales' => $this->totales($filas),
            'opciones' => [
                'cobradores' => collect($filas)->map(static fn (array $f): array => ['id' => $f['cobrador_id'] ?? 0, 'nombre' => $f['cobrador']])
                    ->unique('id')->sortBy('nombre')->values()->all(),
                'distritos' => collect($filas)->pluck('cliente.distrito')->filter()->unique()->sort()->values()->all(),
            ],
        ];
    }

    /**
     * Día (atrasados primero) → cobrador → filas, con totales en cada nivel.
     *
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    private function agrupar(array $filas): array
    {
        $porDia = collect($filas)->groupBy(static fn (array $f): string => $f['dia'] ?? '0000-atrasados')->sortKeys();

        return $porDia->map(fn ($delDia, string $dia): array => [
            'dia' => $dia === '0000-atrasados' ? null : $dia,
            'totales' => $this->totales($delDia->all()),
            'cobradores' => $delDia->groupBy('cobrador')->sortKeys()->map(fn ($deCobrador, string $nombre): array => [
                'cobrador' => $nombre,
                'totales' => $this->totales($deCobrador->all()),
                'filas' => $deCobrador->sortBy(static fn (array $f): string => ($f['cliente']['distrito'] ?? '') . '|' . ($f['cliente']['nombre'] ?? ''))->values()->all(),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @return array{cuotas: int, clientes: int, monto: string}
     */
    private function totales(array $filas): array
    {
        return [
            'cuotas' => count($filas),
            'clientes' => count(array_unique(array_map(static fn (array $f): ?int => $f['cliente']['id'], $filas))),
            'monto' => Dinero::aSoles(array_sum(array_map(static fn (array $f): int => Dinero::aCentavos($f['pendiente']) + Dinero::aCentavos($f['mora']), $filas))),
        ];
    }
}
