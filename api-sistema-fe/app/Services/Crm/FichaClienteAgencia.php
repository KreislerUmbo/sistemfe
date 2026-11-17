<?php

namespace App\Services\Crm;

use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Models\Sale\Note;
use App\Models\Sale\Sale;
use App\Models\Sale\SalePayment;
use App\Services\HoraPeru;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// T-060 (CRM C5) — la ficha del cliente en Agencia: resumen, «Atendido por última vez por» y la
// línea de tiempo (cotizaciones, reservas, comprobantes, pagos, actividades y acciones).
//
// Visibilidad (decidida con el usuario, 16-nov-2026): cotizaciones y reservas con el scope
// propias() (el mismo criterio que CotizacionPolicy/ReservaPolicy::view); comprobantes y pagos solo
// los de reservas visibles; actividades y acciones con referencia solo si se ve esa cotización o
// reserva, sin referencia todas. El resumen usa la misma visibilidad. «Atendido por» se calcula
// sobre todo: muestra solo un nombre.
//
// Ventas directas: su cotización interna no aparece ni cuenta (delCotizador()); su reserva sí.
//
// Comprobantes de una reserva: por reserva_ventas, por sus líneas (sale_detail_items →
// reserva_items: sobrevive a la NC total, que borra la fila de reserva_ventas) y los de adelanto
// (reserva_anticipos). Solo los emitidos a ESTE cliente (una sub-factura a otra empresa no es una
// compra suya).
//
// Consultas: una cantidad fija por tipo de evento, nunca una por elemento (CA4). Cada tipo trae
// solo los primeros (offset + página + 1) por fecha; los primeros N de la mezcla están siempre
// entre los primeros N de cada tipo.
class FichaClienteAgencia
{
    public const POR_PAGINA = 20;

    public const TIPOS = ['cotizacion', 'reserva', 'comprobante', 'pago', 'actividad', 'accion'];

    /** @var array<int, array{reservas: Collection, cotizacionIds: array, ventas: Collection}> */
    private array $cache = [];

    // ── Visibilidad ──────────────────────────────────────────────────

    /** Reservas visibles del cliente (id, codigo, estado, fechas, created_at, origen de su cotización). */
    private function reservas(int $clienteId): Collection
    {
        return $this->contexto($clienteId)['reservas'];
    }

    private function contexto(int $clienteId): array
    {
        if (isset($this->cache[$clienteId])) {
            return $this->cache[$clienteId];
        }

        $reservas = Reserva::query()
            ->propias()
            ->whereHas('alternativa.cotizacion', fn ($q) => $q->where('cliente_id', $clienteId))
            ->get(['id', 'alternativa_id', 'codigo', 'estado', 'fecha_viaje_desde', 'fecha_viaje_hasta', 'created_at']);

        // Cotizaciones del cliente que el usuario puede ver (cualquier origen): solo para filtrar las
        // referencias de actividades y acciones.
        $cotizacionIds = Cotizacion::query()->propias()->where('cliente_id', $clienteId)->pluck('id')->all();

        $this->cache[$clienteId] = [
            'reservas' => $reservas,
            'cotizacionIds' => $cotizacionIds,
            'ventas' => $this->ventasDeReservas($clienteId, $reservas->pluck('id')->all()),
        ];

        return $this->cache[$clienteId];
    }

    /** sale_id => reserva_id de los comprobantes de esas reservas emitidos a este cliente. */
    private function ventasDeReservas(int $clienteId, array $reservaIds): Collection
    {
        if (! $reservaIds) {
            return collect();
        }

        $porVenta = DB::table('reserva_ventas')->whereIn('reserva_id', $reservaIds)->select('sale_id', 'reserva_id')
            ->union(
                DB::table('sale_details as sd')
                    ->join('sale_detail_items as sdi', 'sdi.sale_detail_id', '=', 'sd.id')
                    ->join('reserva_items as ri', 'ri.id', '=', 'sdi.reserva_item_id')
                    ->whereIn('ri.reserva_id', $reservaIds)
                    ->select('sd.sale_id', 'ri.reserva_id')
            )
            ->union(
                DB::table('advances as a')
                    ->join('reserva_anticipos as ra', 'ra.advance_id', '=', 'a.id')
                    ->whereIn('ra.reserva_id', $reservaIds)
                    ->whereNotNull('a.sale_id')
                    ->select('a.sale_id', 'ra.reserva_id')
            );

        return DB::query()->fromSub($porVenta, 'v')
            ->join('sales as s', 's.id', '=', 'v.sale_id')
            ->where('s.client_id', $clienteId)
            ->whereNull('s.deleted_at')
            ->pluck('v.reserva_id', 'v.sale_id');
    }

    private function filtrarReferencias(Builder $query, int $clienteId): Builder
    {
        $contexto = $this->contexto($clienteId);
        $reservaIds = $contexto['reservas']->pluck('id')->all();

        return $query->where('cliente_id', $clienteId)->where(function (Builder $q) use ($contexto, $reservaIds) {
            $q->whereNull('referencia_tipo')
                ->orWhere(fn ($r) => $r->where('referencia_tipo', CrmActividad::REFERENCIA_COTIZACION)->whereIn('referencia_id', $contexto['cotizacionIds'] ?: [0]))
                ->orWhere(fn ($r) => $r->where('referencia_tipo', CrmActividad::REFERENCIA_RESERVA)->whereIn('referencia_id', $reservaIds ?: [0]));
        });
    }

    // ── Resumen ──────────────────────────────────────────────────────

    public function resumen(int $clienteId, ?string $hoy = null): array
    {
        $hoy ??= HoraPeru::hoyTexto();

        $estados = Cotizacion::query()->delCotizador()->propias()->where('cliente_id', $clienteId)
            ->with(Cotizacion::RELACIONES_ESTADO_RESUMEN)
            ->get()
            ->map(fn (Cotizacion $c) => $c->estadoResumen());

        $activas = $this->reservas($clienteId)->where('estado', 'activa');
        $realizados = $activas->filter(fn (Reserva $r) => ($r->fecha_viaje_hasta ?? $r->fecha_viaje_desde)
            && Carbon::parse($r->fecha_viaje_hasta ?? $r->fecha_viaje_desde)->toDateString() < $hoy);
        $ultimo = $activas
            ->filter(fn (Reserva $r) => $r->fecha_viaje_desde && Carbon::parse($r->fecha_viaje_desde)->toDateString() <= $hoy)
            ->sortByDesc(fn (Reserva $r) => Carbon::parse($r->fecha_viaje_desde)->toDateString())
            ->first();

        return [
            'cotizaciones' => $estados->count(),
            'ganadas' => $estados->filter(fn ($e) => $e === 'reservada')->count(),
            'perdidas' => $estados->filter(fn ($e) => $e === 'perdida')->count(),
            'viajes_realizados' => $realizados->count(),
            'ultimo_viaje' => $ultimo ? [
                'reserva_id' => $ultimo->id,
                'codigo' => $ultimo->codigo,
                'desde' => Carbon::parse($ultimo->fecha_viaje_desde)->toDateString(),
                'hasta' => $ultimo->fecha_viaje_hasta ? Carbon::parse($ultimo->fecha_viaje_hasta)->toDateString() : null,
            ] : null,
            'total_comprado' => $this->totalComprado($clienteId),
        ];
    }

    // Comprobantes de sus reservas (factura, boleta, nota de venta y adelanto) − notas de crédito +
    // notas de débito, por moneda. La factura final se guarda neta de los adelantos aplicados, así que
    // el comprobante del adelanto se suma; su devolución es una NC sobre ese comprobante y se resta.
    private function totalComprado(int $clienteId): array
    {
        $saleIds = $this->contexto($clienteId)['ventas']->keys()->all();
        if (! $saleIds) {
            return [];
        }

        $totales = [];
        Sale::query()->whereIn('id', $saleIds)
            ->selectRaw('currency, SUM(total) as monto')->groupBy('currency')->get()
            ->each(function ($fila) use (&$totales) {
                $totales[$fila->currency ?: 'PEN'] = (float) $fila->monto;
            });

        Note::query()->whereIn('sale_id', $saleIds)->where('status', '<>', 'rechazado')
            ->selectRaw("currency, SUM(CASE WHEN tipo_doc = '08' THEN mto_imp_venta ELSE -mto_imp_venta END) as monto")
            ->groupBy('currency')->get()
            ->each(function ($fila) use (&$totales) {
                $moneda = $fila->currency ?: 'PEN';
                $totales[$moneda] = ($totales[$moneda] ?? 0) + (float) $fila->monto;
            });

        return collect($totales)
            ->map(fn ($monto, $moneda) => ['moneda' => $moneda, 'monto' => round($monto, 2)])
            ->sortBy('moneda')->values()->all();
    }

    // ── «Atendido por última vez por» ────────────────────────────────

    // El usuario de la actividad o la cotización más reciente del cliente (calculado, no guardado:
    // respuesta 3 de la obs. 16). Sobre todo el cliente, sin filtrar visibilidad: es solo un nombre.
    public function atendidoPorUltimaVez(int $clienteId): ?array
    {
        $actividad = CrmActividad::query()->where('cliente_id', $clienteId)->whereNotNull('usuario_id')
            ->orderByDesc('created_at')->orderByDesc('id')->first(['usuario_id', 'created_at']);
        $cotizacion = Cotizacion::query()->where('cliente_id', $clienteId)->whereNotNull('vendedor_id')
            ->orderByDesc('created_at')->orderByDesc('id')->first(['vendedor_id', 'created_at']);

        $candidatos = collect([
            $actividad ? ['usuario_id' => $actividad->usuario_id, 'fecha' => $actividad->created_at] : null,
            $cotizacion ? ['usuario_id' => $cotizacion->vendedor_id, 'fecha' => $cotizacion->created_at] : null,
        ])->filter();

        $ultimo = $candidatos->sortByDesc(fn ($c) => $c['fecha']->getTimestamp())->first();
        if (! $ultimo) {
            return null;
        }

        $usuario = DB::table('users')->where('id', $ultimo['usuario_id'])->first(['id', 'name', 'surname']);

        return $usuario ? [
            'usuario_id' => $usuario->id,
            'nombre' => trim(($usuario->name ?? '') . ' ' . ($usuario->surname ?? '')),
            'fecha' => $ultimo['fecha']->toJSON(),
        ] : null;
    }

    // ── Línea de tiempo ──────────────────────────────────────────────

    /** @return array{eventos: array, hay_mas: bool} */
    public function lineaTiempo(int $clienteId, ?string $tipo = null, int $pagina = 1): array
    {
        $pagina = max(1, $pagina);
        $offset = ($pagina - 1) * self::POR_PAGINA;
        $limite = $offset + self::POR_PAGINA + 1;

        $tipos = $tipo && in_array($tipo, self::TIPOS, true) ? [$tipo] : self::TIPOS;

        $eventos = collect();
        foreach ($tipos as $t) {
            $eventos = $eventos->concat(match ($t) {
                'cotizacion' => $this->eventosCotizaciones($clienteId, $limite),
                'reserva' => $this->eventosReservas($clienteId, $limite),
                'comprobante' => $this->eventosComprobantes($clienteId, $limite),
                'pago' => $this->eventosPagos($clienteId, $limite),
                'actividad' => $this->eventosActividades($clienteId, $limite),
                'accion' => $this->eventosAcciones($clienteId, $limite),
            });
        }

        // Más reciente primero; a igual instante, por id para que la paginación sea estable.
        $ordenados = $eventos->sort(fn ($a, $b) => [$b['orden'], $b['id']] <=> [$a['orden'], $a['id']])->values();

        return [
            'eventos' => $ordenados->slice($offset, self::POR_PAGINA)
                ->map(fn ($e) => collect($e)->except('orden')->all())->values()->all(),
            'hay_mas' => $ordenados->count() > $offset + self::POR_PAGINA,
        ];
    }

    private static function nombre($usuario): ?string
    {
        return $usuario ? trim(($usuario->name ?? '') . ' ' . ($usuario->surname ?? '')) : null;
    }

    // id con prefijo ('cotizacion-5', 'nota-3'): salen de varias tablas y la lista de Vue necesita
    // una key única.
    private static function evento(string $tipo, string $clave, CarbonInterface $fecha, array $datos): array
    {
        return ['id' => $clave, 'tipo' => $tipo, 'fecha' => $fecha->toJSON(), 'orden' => $fecha->getTimestamp()] + $datos;
    }

    private function eventosCotizaciones(int $clienteId, int $limite): Collection
    {
        return Cotizacion::query()->delCotizador()->propias()->where('cliente_id', $clienteId)
            ->with(array_merge(Cotizacion::RELACIONES_ESTADO_RESUMEN, ['vendedor:id,name,surname', 'resultadoMotivo:id,tipo,nombre', 'origenContacto:id,nombre']))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limite)
            ->get()
            ->map(fn (Cotizacion $c) => self::evento('cotizacion', "cotizacion-{$c->id}", $c->created_at, [
                'titulo' => $c->codigo,
                'detalle' => $c->destino,
                'estado' => $c->estadoResumen(),
                'resultado' => $c->resultadoMotivo ? [
                    'tipo' => $c->resultadoMotivo->tipo,
                    'motivo' => $c->resultadoMotivo->nombre,
                    'detalle' => $c->resultado_detalle,
                ] : null,
                'origen' => $c->origenContacto?->nombre,
                'usuario' => self::nombre($c->vendedor),
                'enlace' => ['cotizacion_id' => $c->id],
            ]));
    }

    private function eventosReservas(int $clienteId, int $limite): Collection
    {
        $reservas = $this->reservas($clienteId)->sortByDesc(fn ($r) => [$r->created_at->getTimestamp(), $r->id])->take($limite);
        // Destino y si es venta directa: una consulta para todas.
        $cotizaciones = DB::table('alternativas as a')->join('cotizaciones as c', 'c.id', '=', 'a.cotizacion_id')
            ->whereIn('a.id', $reservas->pluck('alternativa_id')->all() ?: [0])
            ->get(['a.id', 'c.origen', 'c.destino'])->keyBy('id');

        return $reservas->map(fn (Reserva $r) => self::evento('reserva', "reserva-{$r->id}", $r->created_at, [
            'titulo' => $r->codigo,
            'detalle' => $cotizaciones[$r->alternativa_id]->destino ?? null,
            'estado' => $r->estado,
            'venta_directa' => ($cotizaciones[$r->alternativa_id]->origen ?? null) === Cotizacion::ORIGEN_VENTA_DIRECTA,
            'viaje' => [
                'desde' => $r->fecha_viaje_desde ? Carbon::parse($r->fecha_viaje_desde)->toDateString() : null,
                'hasta' => $r->fecha_viaje_hasta ? Carbon::parse($r->fecha_viaje_hasta)->toDateString() : null,
            ],
            'enlace' => ['reserva_id' => $r->id],
        ]))->values();
    }

    private static function numeroComprobante(Sale $s): string
    {
        if ($s->n_operacion) {
            return $s->n_operacion;
        }

        return $s->correlativo ? "{$s->serie}-{$s->correlativo}" : ($s->serie ? "{$s->serie} (sin número)" : (string) $s->n_transaction);
    }

    private function eventosComprobantes(int $clienteId, int $limite): Collection
    {
        $ventas = $this->contexto($clienteId)['ventas'];
        if ($ventas->isEmpty()) {
            return collect();
        }

        $comprobantes = Sale::query()->whereIn('id', $ventas->keys()->all())
            ->with('user:id,name,surname')
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limite)
            ->get(['id', 'type', 'serie', 'correlativo', 'n_operacion', 'n_transaction', 'tipo_comprobante_codigo', 'currency', 'total', 'user_id', 'created_at'])
            ->map(fn (Sale $s) => self::evento('comprobante', "comprobante-{$s->id}", $s->created_at, [
                'titulo' => self::numeroComprobante($s),
                'clase' => $s->type === 'advance' ? 'adelanto' : ($s->tipo_comprobante_codigo ?? null),
                'monto' => (float) $s->total,
                'moneda' => $s->currency ?: 'PEN',
                'enviado' => (bool) $s->n_operacion,
                'usuario' => self::nombre($s->user),
                'enlace' => ['reserva_id' => $ventas[$s->id] ?? null],
            ]));

        $notas = Note::query()->whereIn('sale_id', $ventas->keys()->all())
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limite)
            ->get(['id', 'sale_id', 'tipo_doc', 'serie', 'correlativo', 'n_operacion', 'des_motivo', 'currency', 'mto_imp_venta', 'status', 'serie_afectada', 'correlativo_afectado', 'created_at'])
            ->map(fn (Note $n) => self::evento('comprobante', "nota-{$n->id}", $n->created_at, [
                'titulo' => $n->n_operacion ?: ($n->correlativo ? "{$n->serie}-{$n->correlativo}" : "{$n->serie} (sin número)"),
                'clase' => $n->tipo_doc === '08' ? 'nota_debito' : 'nota_credito',
                'detalle' => trim(($n->des_motivo ?? '') . ($n->serie_afectada ? " · afecta a {$n->serie_afectada}-{$n->correlativo_afectado}" : '')),
                'monto' => (float) $n->mto_imp_venta,
                'moneda' => $n->currency ?: 'PEN',
                'enviado' => (bool) $n->n_operacion,
                'enlace' => ['reserva_id' => $ventas[$n->sale_id] ?? null],
            ]));

        return $comprobantes->concat($notas);
    }

    private function eventosPagos(int $clienteId, int $limite): Collection
    {
        $ventas = $this->contexto($clienteId)['ventas'];
        if ($ventas->isEmpty()) {
            return collect();
        }

        return SalePayment::query()->whereIn('sale_id', $ventas->keys()->all())
            ->with('sale:id,serie,correlativo,n_operacion,n_transaction,currency')
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limite)
            ->get()
            ->map(fn (SalePayment $p) => self::evento('pago', "pago-{$p->id}", $p->created_at, [
                'titulo' => $p->method_payment,
                'detalle' => $p->sale ? self::numeroComprobante($p->sale) : null,
                'monto' => (float) ($p->monto_pagado ?? $p->amount),
                'moneda' => $p->moneda_pago ?: ($p->sale?->currency ?: 'PEN'),
                'traspaso' => $p->origen === 'traspaso',
                'nro_operacion' => $p->nro_operacion,
                'enlace' => ['reserva_id' => $ventas[$p->sale_id] ?? null],
            ]));
    }

    private function eventosActividades(int $clienteId, int $limite): Collection
    {
        return $this->filtrarReferencias(CrmActividad::query(), $clienteId)
            ->with('usuario:id,name,surname')
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limite)
            ->get()
            ->map(fn (CrmActividad $a) => self::evento('actividad', "actividad-{$a->id}", $a->created_at, [
                'titulo' => $a->tipo,
                'canal' => $a->canal,
                'nota' => $a->nota,
                'usuario' => self::nombre($a->usuario),
                'enlace' => self::enlaceReferencia($a->referencia_tipo, $a->referencia_id),
            ]));
    }

    // Hechas por cuándo se hicieron; pendientes por su día (00:00 de Perú = 05:00 UTC, Perú no tiene
    // horario de verano), así lo que viene queda arriba.
    private function eventosAcciones(int $clienteId, int $limite): Collection
    {
        return $this->filtrarReferencias(CrmAccion::query(), $clienteId)
            ->with('responsable:id,name,surname')
            ->orderByRaw("COALESCE(hecha_en, fecha + interval '5 hours') DESC")->orderByDesc('id')->limit($limite)
            ->get()
            ->map(fn (CrmAccion $a) => self::evento('accion', "accion-{$a->id}",
                $a->hecha_en ? Carbon::parse($a->hecha_en) : Carbon::parse(HoraPeru::inicioDiaUtc($a->fecha->toDateString()), 'UTC'), [
                    'titulo' => CrmAccion::TIPOS[$a->tipo] ?? $a->tipo,
                    'nota' => $a->descripcion,
                    'pendiente' => $a->hecha_en === null,
                    'dia' => $a->fecha->toDateString(),
                    'usuario' => self::nombre($a->responsable),
                    'enlace' => self::enlaceReferencia($a->referencia_tipo, $a->referencia_id),
                ]));
    }

    private static function enlaceReferencia(?string $tipo, ?int $id): ?array
    {
        return match ($tipo) {
            CrmActividad::REFERENCIA_COTIZACION => ['cotizacion_id' => $id],
            CrmActividad::REFERENCIA_RESERVA => ['reserva_id' => $id],
            default => null,
        };
    }
}
