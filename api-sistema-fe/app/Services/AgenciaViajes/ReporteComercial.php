<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Services\HoraPeru;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

// T-062 (CRM C6b) — «Reportes comerciales»: embudo, por origen, por vendedor, motivos y tiempo de
// respuesta de las cotizaciones del Cotizador creadas en un rango de días de Perú.
//
// Cada cotización cae en UN resultado (así origen y vendedor suman lo mismo que el embudo):
// - perdida:  la cerraron con «Cerrar como perdida» (perdida_en).
// - ganada:   tiene alternativa aceptada o reemplazada (una modificación aplicada) y su reserva no
//             está cancelada.
// - anulada:  se ganó, pero la reserva se canceló. Fila propia, no suma al monto vendido
//             (decisión del usuario, 16-nov-2026).
// - abierta:  lo demás (mismo criterio que Cotizacion::scopeAbiertas(): las vencidas cuentan).
// «Enviada» = alguna alternativa con fecha_envio, o ganada/anulada (aceptar desde borrador está
// permitido y no deja fecha de envío). El tiempo de respuesta solo usa la fecha de envío real.
//
// Monto vendido: total de la alternativa a la que apunta HOY la reserva (tras una modificación
// es la versión nueva), en su moneda; PEN y USD por separado, sin convertir.
//
// Todo se agrega en SQL: 4 consultas, sin importar cuántas cotizaciones haya (CA4).
class ReporteComercial
{
    public const RESULTADOS = ['ganada', 'perdida', 'abierta', 'anulada'];

    /**
     * @param  array{desde: string, hasta: string, vendedor_id?: ?int, origen_contacto_id?: ?int, sin_origen?: bool, sin_vendedor?: bool}  $filtros
     *         desde/hasta son días de Perú (Y-m-d), ambos incluidos.
     */
    public function generar(array $filtros): array
    {
        $filas = $this->filas($filtros);

        $totales = $this->agregar(DB::query()->fromSub($filas, 'f'))
            ->selectRaw("percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM f.primer_envio - f.created_at) / 3600) FILTER (WHERE f.primer_envio IS NOT NULL) AS mediana_horas")
            ->selectRaw('count(f.primer_envio) AS con_envio')
            ->first();

        $porOrigen = $this->agregar(DB::query()->fromSub($filas, 'f'))
            ->leftJoin('crm_origenes_contacto as o', 'o.id', '=', 'f.origen_contacto_id')
            ->addSelect('f.origen_contacto_id as id', 'o.nombre', 'o.activo')
            ->groupBy('f.origen_contacto_id', 'o.nombre', 'o.activo', 'o.orden')
            ->orderByRaw('count(*) DESC, o.orden NULLS LAST, o.nombre')
            ->get();

        $porVendedor = $this->agregar(DB::query()->fromSub($filas, 'f'))
            ->leftJoin('users as u', 'u.id', '=', 'f.vendedor_id')
            ->addSelect('f.vendedor_id as id', 'u.name', 'u.surname', 'u.state', 'u.deleted_at')
            ->groupBy('f.vendedor_id', 'u.name', 'u.surname', 'u.state', 'u.deleted_at')
            ->orderByRaw('count(*) DESC, u.name')
            ->get();

        $motivos = DB::query()->fromSub($filas, 'f')
            ->leftJoin('crm_motivos_resultado as m', 'm.id', '=', 'f.resultado_motivo_id')
            ->whereIn('f.resultado', ['ganada', 'anulada', 'perdida'])
            ->selectRaw("CASE WHEN f.resultado = 'perdida' THEN 'perdida' ELSE 'ganada' END AS tipo")
            ->addSelect('f.resultado_motivo_id as id', 'm.nombre', 'm.orden')
            ->selectRaw('count(*) AS cantidad')
            ->groupByRaw("1, f.resultado_motivo_id, m.nombre, m.orden")
            ->get();

        return [
            'embudo' => $this->embudo($totales),
            'por_origen' => $porOrigen->map(fn ($f) => $this->filaGrupo($f, [
                'id' => $f->id,
                'nombre' => $f->id ? $f->nombre : 'Sin origen',
                'inactivo' => $f->id !== null && ! $f->activo,
            ]))->values()->all(),
            'por_vendedor' => $porVendedor->map(fn ($f) => $this->filaGrupo($f, [
                'id' => $f->id,
                'nombre' => $f->id ? trim($f->name . ' ' . $f->surname) : 'Sin vendedor',
                'inactivo' => $f->id !== null && ($f->deleted_at !== null || (int) $f->state === \App\Models\User::STATE_INACTIVO),
            ]))->values()->all(),
            'motivos' => [
                'ganada' => $this->motivosDe($motivos, 'ganada', (int) $totales->ganadas + (int) $totales->anuladas),
                'perdida' => $this->motivosDe($motivos, 'perdida', (int) $totales->perdidas),
            ],
            'tiempo_respuesta' => [
                'mediana_horas' => $totales->mediana_horas !== null ? round((float) $totales->mediana_horas, 1) : null,
                'cotizaciones_con_envio' => (int) $totales->con_envio,
            ],
        ];
    }

    // Una fila por cotización con lo que el reporte necesita, ya con su resultado y monto.
    private function filas(array $filtros): Builder
    {
        $base = Cotizacion::query()->propias()->delCotizador()
            ->where('cotizaciones.created_at', '>=', HoraPeru::inicioDiaUtc($filtros['desde']))
            ->where('cotizaciones.created_at', '<', HoraPeru::finDiaUtc($filtros['hasta']))
            ->when($filtros['vendedor_id'] ?? null, fn ($q, $id) => $q->where('cotizaciones.vendedor_id', $id))
            ->when($filtros['sin_vendedor'] ?? false, fn ($q) => $q->whereNull('cotizaciones.vendedor_id'))
            ->when($filtros['origen_contacto_id'] ?? null, fn ($q, $id) => $q->where('cotizaciones.origen_contacto_id', $id))
            ->when($filtros['sin_origen'] ?? false, fn ($q) => $q->whereNull('cotizaciones.origen_contacto_id'))
            ->select('cotizaciones.id', 'cotizaciones.vendedor_id', 'cotizaciones.origen_contacto_id', 'cotizaciones.created_at',
                'cotizaciones.perdida_en', 'cotizaciones.resultado_motivo_id')
            ->selectSub(fn ($q) => $q->from('alternativas')->whereColumn('alternativas.cotizacion_id', 'cotizaciones.id')
                ->selectRaw('min(fecha_envio)'), 'primer_envio')
            // Nunca hay más de una aceptada o reemplazada por cotización (aceptar descarta las demás).
            ->selectSub(fn ($q) => $q->from('alternativas')->whereColumn('alternativas.cotizacion_id', 'cotizaciones.id')
                ->whereIn('estado', ['aceptada', Alternativa::ESTADO_REEMPLAZADA])->orderBy('id')->limit(1)
                ->select('id'), 'alternativa_ganada_id')
            ->toBase();

        return DB::query()->fromSub($base, 'b')
            ->leftJoin('alternativas as ag', 'ag.id', '=', 'b.alternativa_ganada_id')
            // Reemplazada: su reserva se encuentra por la modificación aplicada (la reserva ya apunta a la versión nueva).
            ->leftJoin('reserva_modificaciones as rm', function ($j) {
                $j->on('rm.alternativa_vendida_id', '=', 'ag.id')
                    ->where('ag.estado', '=', Alternativa::ESTADO_REEMPLAZADA)
                    ->where('rm.estado', '=', ReservaModificacion::ESTADO_APLICADA);
            })
            ->leftJoin('reserva as r', function ($j) {
                $j->on(function ($o) {
                    $o->where(fn ($w) => $w->where('ag.estado', '=', 'aceptada')->whereColumn('r.alternativa_id', 'ag.id'))
                        ->orWhereColumn('r.id', 'rm.reserva_id');
                });
            })
            ->leftJoin('alternativas as av', 'av.id', '=', 'r.alternativa_id')
            ->select('b.id', 'b.vendedor_id', 'b.origen_contacto_id', 'b.created_at', 'b.primer_envio', 'b.resultado_motivo_id')
            ->selectRaw("CASE
                WHEN b.perdida_en IS NOT NULL THEN 'perdida'
                WHEN b.alternativa_ganada_id IS NULL THEN 'abierta'
                WHEN r.estado = 'cancelada' THEN 'anulada'
                ELSE 'ganada' END AS resultado")
            ->selectRaw('COALESCE(av.total, ag.total) AS monto')
            ->selectRaw('COALESCE(av.moneda_cotizacion, ag.moneda_cotizacion) AS moneda');
    }

    // Conteos y montos comunes al embudo, a origen y a vendedor. Montos en numeric (sin float).
    private function agregar(Builder $q): Builder
    {
        return $q->selectRaw('count(*) AS consultas')
            ->selectRaw("count(*) FILTER (WHERE f.primer_envio IS NOT NULL OR f.resultado IN ('ganada', 'anulada')) AS enviadas")
            ->selectRaw("count(*) FILTER (WHERE f.resultado = 'ganada') AS ganadas")
            ->selectRaw("count(*) FILTER (WHERE f.resultado = 'perdida') AS perdidas")
            ->selectRaw("count(*) FILTER (WHERE f.resultado = 'abierta') AS abiertas")
            ->selectRaw("count(*) FILTER (WHERE f.resultado = 'anulada') AS anuladas")
            ->selectRaw("COALESCE(sum(f.monto) FILTER (WHERE f.resultado = 'ganada' AND f.moneda = 'PEN'), 0)::numeric(14,2)::text AS monto_pen")
            ->selectRaw("COALESCE(sum(f.monto) FILTER (WHERE f.resultado = 'ganada' AND f.moneda = 'USD'), 0)::numeric(14,2)::text AS monto_usd");
    }

    private function conteos(object $f): array
    {
        $consultas = (int) $f->consultas;
        $ganadas = (int) $f->ganadas;

        return [
            'consultas' => $consultas,
            'enviadas' => (int) $f->enviadas,
            'ganadas' => $ganadas,
            'perdidas' => (int) $f->perdidas,
            'abiertas' => (int) $f->abiertas,
            'anuladas' => (int) $f->anuladas,
            // Conversión = ganadas / consultas (las anuladas no cuentan como venta).
            'conversion_pct' => $this->pct($ganadas, $consultas),
            'monto' => ['PEN' => $f->monto_pen, 'USD' => $f->monto_usd],
        ];
    }

    private function embudo(object $t): array
    {
        $c = $this->conteos($t);
        $c['pct'] = [];
        foreach (['enviadas', 'ganadas', 'perdidas', 'abiertas', 'anuladas'] as $clave) {
            $c['pct'][$clave] = $this->pct($c[$clave], $c['consultas']);
        }

        return $c;
    }

    private function filaGrupo(object $f, array $cabecera): array
    {
        return $cabecera + $this->conteos($f);
    }

    // % sobre todas las de ese tipo, con «Sin motivo» para las que no lo tienen (las filas suman 100 %).
    private function motivosDe($motivos, string $tipo, int $total): array
    {
        return $motivos->where('tipo', $tipo)
            ->sortBy(fn ($m) => [$m->id === null ? 1 : 0, -$m->cantidad, $m->orden ?? 0])
            ->map(fn ($m) => [
                'id' => $m->id,
                'nombre' => $m->id ? $m->nombre : 'Sin motivo',
                'cantidad' => (int) $m->cantidad,
                'pct' => $this->pct((int) $m->cantidad, $total),
            ])->values()->all();
    }

    private function pct(int $parte, int $total): float
    {
        return $total > 0 ? round($parte * 100 / $total, 1) : 0.0;
    }
}
