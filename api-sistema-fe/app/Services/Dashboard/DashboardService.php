<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Http\Controllers\Advance\AdvanceController;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\Sale\Sale;
use App\Models\User;
use App\Services\AgenciaViajes\AsignacionOperativa;
use App\Services\Creditos\Dinero;
use App\Services\CreditSummaryCalculator;
use App\Services\Tenancy\GiroActual;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Inicio (Dashboard) de los giros retail y agencia de viajes con datos del tenant actual. Cada
 * bloque sale solo si el giro lo usa y el usuario tiene el permiso de la pantalla de origen; el
 * giro créditos tiene su propio Panel (04d) y no pasa por aquí.
 *
 * Decisiones aprobadas (02-oct-2026): montos separados por moneda (sin convertir), ventas netas
 * = ventas − notas de crédito aceptadas (con el bruto al lado), la Nota de Venta interna cuenta
 * como venta pero se informa aparte de lo fiscal.
 */
class DashboardService
{
    public const ZONA = 'America/Lima';
    private const DIAS_GRAFICO = 30;
    private const DIAS_PROXIMOS = 7;
    private const MAXIMO_LISTA = 8;
    private const TOP_PRODUCTOS = 5;

    /** Fecha de negocio de una venta: la de emisión; si falta, la de registro en hora de Lima. */
    private const DIA_VENTA = "coalesce(sales.date::date, (sales.created_at at time zone 'UTC' at time zone 'America/Lima')::date)";
    private const DIA_NOTA = "(notes.created_at at time zone 'UTC' at time zone 'America/Lima')::date";

    public function __construct(
        private readonly GiroActual $giro,
        private readonly CreditSummaryCalculator $creditos,
    ) {
    }

    /** @return array<string, mixed> */
    public function inicio(User $usuario, ?CarbonImmutable $ahora = null): array
    {
        $hoy = ($ahora ?? CarbonImmutable::now(self::ZONA))->setTimezone(self::ZONA)->startOfDay();
        $agencia = $this->giro->es('agencia_viajes');
        $bloques = [];

        if ($usuario->can('list_sale')) {
            $bloques['ventas'] = $this->ventas($hoy);
            $bloques['ventas_dias'] = $this->ventasPorDia($hoy);
            $bloques['sunat'] = $this->pendientesSunat($usuario);
            $bloques['por_cobrar'] = $this->porCobrar($hoy);
            if (! $agencia) {
                $bloques['productos'] = $this->productos($hoy);
            }
        }
        if ($agencia && $usuario->canAny(['cotizaciones.ver', 'cotizaciones.ver_todas'])) {
            $bloques['cotizaciones'] = $this->cotizaciones($hoy);
        }
        if ($agencia && $usuario->canAny(['reservas.ver', 'reservas.ver_todas'])) {
            $bloques['proximos'] = $this->proximos($hoy);
        }

        return [
            'giro' => $this->giro->valor(),
            'hoy' => $hoy->toDateString(),
            'bloques' => $bloques,
        ];
    }

    // ── Ventas ──

    /** @return array<string, mixed> */
    private function ventas(CarbonImmutable $hoy): array
    {
        $inicioMes = $hoy->startOfMonth();
        // Mes anterior "a la misma fecha": del 1 al mismo día (o al último día si ese mes es más corto).
        $inicioAnterior = $inicioMes->subMonthNoOverflow();
        $finAnterior = $inicioAnterior->setDay(min($hoy->day, $inicioAnterior->daysInMonth));

        $mes = $this->resumenVentas($inicioMes, $hoy);
        $anterior = collect($this->resumenVentas($inicioAnterior, $finAnterior))->keyBy('moneda');

        return [
            'hoy' => $this->resumenVentas($hoy, $hoy),
            'mes' => array_map(static function (array $fila) use ($anterior): array {
                $previo = $anterior->get($fila['moneda']);
                $netoPrevio = $previo ? Dinero::aCentavos($previo['neto']) : 0;

                return [
                    ...$fila,
                    'neto_mes_anterior' => Dinero::aSoles($netoPrevio),
                    'variacion' => self::variacion(Dinero::aCentavos($fila['neto']), $netoPrevio),
                ];
            }, $mes),
            'periodo_mes' => ['desde' => $inicioMes->toDateString(), 'hasta' => $hoy->toDateString()],
            'periodo_anterior' => ['desde' => $inicioAnterior->toDateString(), 'hasta' => $finAnterior->toDateString()],
        ];
    }

    /**
     * Por moneda: bruto, notas de crédito aceptadas, neto, comprobantes (fiscales e internos) y
     * ticket promedio (bruto ÷ comprobantes, en centavos enteros).
     *
     * @return list<array<string, mixed>>
     */
    private function resumenVentas(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $ventas = $this->ventasEntre($desde, $hasta)
            ->selectRaw("sales.currency as moneda,
                count(*) as comprobantes,
                count(*) filter (where sales.tipo_comprobante_codigo = 'NV') as internos,
                coalesce(sum(sales.total), 0)::numeric(14,2)::text as bruto,
                coalesce(sum(sales.total) filter (where sales.tipo_comprobante_codigo = 'NV'), 0)::numeric(14,2)::text as bruto_interno")
            ->groupBy('sales.currency')
            ->get()
            ->keyBy('moneda');

        $notas = DB::table('notes')
            ->whereNull('notes.deleted_at')
            ->where('notes.tipo_doc', '07')
            ->where('notes.status', 'aceptado')
            ->whereRaw(self::DIA_NOTA . ' between ? and ?', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('notes.currency as moneda, count(*) as cantidad, coalesce(sum(notes.mto_imp_venta), 0)::numeric(14,2)::text as monto')
            ->groupBy('notes.currency')
            ->get()
            ->keyBy('moneda');

        $monedas = $ventas->keys()->merge($notas->keys())->unique()->sort()->values();

        return $monedas->map(static function (string $moneda) use ($ventas, $notas): array {
            $v = $ventas->get($moneda);
            $n = $notas->get($moneda);
            $bruto = $v ? Dinero::aCentavos($v->bruto) : 0;
            $interno = $v ? Dinero::aCentavos($v->bruto_interno) : 0;
            $credito = $n ? Dinero::aCentavos($n->monto) : 0;
            $comprobantes = $v ? (int) $v->comprobantes : 0;

            return [
                'moneda' => $moneda,
                'bruto' => Dinero::aSoles($bruto),
                'notas_credito' => Dinero::aSoles($credito),
                'notas_credito_cantidad' => $n ? (int) $n->cantidad : 0,
                'neto' => Dinero::aSoles($bruto - $credito),
                'comprobantes' => $comprobantes,
                'internos' => $v ? (int) $v->internos : 0,
                'fiscal' => Dinero::aSoles($bruto - $interno),
                'interno' => Dinero::aSoles($interno),
                'ticket_promedio' => Dinero::aSoles($comprobantes > 0 ? intdiv($bruto + intdiv($comprobantes, 2), $comprobantes) : 0),
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function ventasPorDia(CarbonImmutable $hoy): array
    {
        $desde = $hoy->subDays(self::DIAS_GRAFICO - 1);
        $filas = $this->ventasEntre($desde, $hoy)
            ->selectRaw(self::DIA_VENTA . "::text as dia, sales.currency as moneda, sum(sales.total)::numeric(14,2)::text as monto")
            ->groupByRaw(self::DIA_VENTA . ', sales.currency')
            ->get();

        $monedas = $filas->pluck('moneda')->unique()->sort()->values()->all();
        $porDia = $filas->groupBy('dia');
        $dias = [];
        for ($d = $desde; $d->lte($hoy); $d = $d->addDay()) {
            $delDia = $porDia->get($d->toDateString(), collect())->keyBy('moneda');
            $dias[] = [
                'fecha' => $d->toDateString(),
                'montos' => array_combine($monedas, array_map(static fn (string $m): string => $delDia->get($m)?->monto ?? '0.00', $monedas)) ?: (object) [],
            ];
        }

        return ['monedas' => $monedas, 'dias' => $dias];
    }

    /** Ventas reales (sin comprobantes de adelanto) con fecha de negocio en el rango. */
    private function ventasEntre(CarbonImmutable $desde, CarbonImmutable $hasta): QueryBuilder
    {
        return DB::table('sales')
            ->whereNull('sales.deleted_at')
            ->where('sales.type', '!=', 'advance')
            ->whereRaw(self::DIA_VENTA . ' between ? and ?', [$desde->toDateString(), $hasta->toDateString()]);
    }

    /**
     * Comprobantes fiscales por enviar (sin correlativo: se reserva al enviar) y con envío fallido
     * (correlativo sin CDR), más notas pendientes o rechazadas. La Nota de Venta no va a SUNAT.
     *
     * @return array<string, mixed>
     */
    private function pendientesSunat(User $usuario): array
    {
        $fiscales = Sale::query()->soloDocumentosFiscales()->whereNull('cdr');
        $porEnviar = (clone $fiscales)->whereNull('correlativo');
        $conError = (clone $fiscales)->whereNotNull('correlativo');

        $lista = (clone $fiscales)->with('client:id,full_name')
            ->orderByRaw(self::DIA_VENTA)->orderBy('id')
            ->limit(self::MAXIMO_LISTA)
            ->get(['id', 'client_id', 'serie', 'correlativo', 'type', 'currency', 'total', 'date', 'created_at', 'sunat_error_message'])
            ->map(static fn (Sale $s): array => [
                'id' => $s->id,
                'comprobante' => $s->correlativo ? "{$s->serie}-{$s->correlativo}" : null,
                'adelanto' => $s->type === 'advance',
                'cliente' => $s->client?->full_name,
                'fecha' => $s->date ?? $s->created_at?->setTimezone(self::ZONA)->toDateString(),
                'moneda' => $s->currency,
                'total' => number_format((float) $s->total, 2, '.', ''),
                'estado' => $s->correlativo ? 'error' : 'por_enviar',
                'error' => $s->sunat_error_message,
            ])->all();

        $notas = $usuario->can('list_nota_electronica')
            ? DB::table('notes')->whereNull('deleted_at')
                ->selectRaw("count(*) filter (where status in ('pendiente', 'enviando')) as pendientes, count(*) filter (where status = 'rechazado') as rechazadas")
                ->first()
            : null;

        return [
            'por_enviar' => $porEnviar->count(),
            'con_error' => $conError->count(),
            'lista' => $lista,
            'notas' => $notas ? ['pendientes' => (int) $notas->pendientes, 'rechazadas' => (int) $notas->rechazadas] : null,
        ];
    }

    /**
     * Saldo de ventas por cobrar por moneda y las que tienen cuotas vencidas, con el mismo cálculo
     * que Cuentas por Cobrar (CreditSummaryCalculator).
     *
     * @return array<string, mixed>
     */
    private function porCobrar(CarbonImmutable $hoy): array
    {
        $ventas = Sale::where('saldo_pendiente', '>', 0)->get();
        $referencia = \Illuminate\Support\Carbon::parse($hoy->toDateString());
        $grupos = [];
        foreach ($ventas as $venta) {
            $resumen = $this->creditos->resumenVenta($venta, $referencia);
            $g = &$grupos[$venta->currency];
            $g ??= ['moneda' => $venta->currency, 'ventas' => 0, 'saldo' => 0, 'vencidas' => 0, 'saldo_vencido' => 0, 'clientes' => []];
            $saldo = Dinero::aCentavos((string) $venta->saldo_pendiente);
            $g['ventas']++;
            $g['saldo'] += $saldo;
            $g['clientes'][$venta->client_id] = true;
            if ($resumen['estado'] === 'vencida') {
                $g['vencidas']++;
                $g['saldo_vencido'] += $saldo;
            }
            unset($g);
        }
        ksort($grupos);

        return ['por_moneda' => array_values(array_map(static fn (array $g): array => [
            'moneda' => $g['moneda'],
            'ventas' => $g['ventas'],
            'clientes' => count($g['clientes']),
            'saldo' => Dinero::aSoles($g['saldo']),
            'vencidas' => $g['vencidas'],
            'saldo_vencido' => Dinero::aSoles($g['saldo_vencido']),
        ], $grupos))];
    }

    /**
     * Más vendidos del mes por cantidad (sin mezclar monedas) y productos con control de stock
     * que se quedaron sin existencias.
     *
     * @return array<string, mixed>
     */
    private function productos(CarbonImmutable $hoy): array
    {
        $top = $this->ventasEntre($hoy->startOfMonth(), $hoy)
            ->join('sale_details', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'products.id', '=', 'sale_details.product_id')
            ->whereNull('sale_details.deleted_at')
            ->selectRaw('products.id, products.title, sum(sale_details.quantity)::numeric(14,2)::text as cantidad, count(distinct sales.id) as ventas')
            ->groupBy('products.id', 'products.title')
            ->orderByRaw('sum(sale_details.quantity) desc')
            ->orderBy('products.title')
            ->limit(self::TOP_PRODUCTOS)
            ->get()
            ->map(static fn ($p): array => ['id' => $p->id, 'producto' => $p->title, 'cantidad' => rtrim(rtrim($p->cantidad, '0'), '.'), 'ventas' => (int) $p->ventas])
            ->all();

        // Sin el producto ficticio de los adelantos (no es mercadería).
        $sinStock = DB::table('products')->whereNull('deleted_at')->where('controla_stock', true)->where('stock', '<=', 0)
            ->where(static fn ($q) => $q->whereNull('sku')->orWhere('sku', '!=', AdvanceController::SKU_PRODUCTO_ADELANTO));

        return [
            'top' => $top,
            'sin_stock' => (clone $sinStock)->count(),
            'sin_stock_lista' => $sinStock->orderBy('title')->limit(self::MAXIMO_LISTA)->get(['id', 'title', 'stock'])
                ->map(static fn ($p): array => ['id' => $p->id, 'producto' => $p->title, 'stock' => (float) $p->stock])->all(),
        ];
    }

    // ── Agencia de viajes ──

    /**
     * Cotizaciones creadas este mes (las propias si el usuario no ve todas) por estado resumido,
     * mismo criterio que el listado del Cotizador.
     *
     * @return array<string, mixed>
     */
    private function cotizaciones(CarbonImmutable $hoy): array
    {
        $estados = Cotizacion::propias()
            ->whereBetween('created_at', [$hoy->startOfMonth()->utc(), $hoy->endOfDay()->utc()])
            ->with('alternativas.reserva')
            ->get()
            ->map(static fn (Cotizacion $c): string => $c->estadoResumen())
            ->countBy();
        $creadas = $estados->sum();

        return [
            'creadas' => $creadas,
            'reservadas' => $estados->get('reservada', 0),
            'enviadas' => $estados->get('enviada', 0),
            'borrador' => $estados->get('borrador', 0),
            'conversion' => self::porcentaje($estados->get('reservada', 0), $creadas),
        ];
    }

    /**
     * Próximos días: viajes que empiezan (reservas activas propias), salidas operativas con sus
     * pasajeros y servicios todavía sin guía o proveedor (criterio del Reporte Operativo).
     *
     * @return array<string, mixed>
     */
    private function proximos(CarbonImmutable $hoy): array
    {
        $hasta = $hoy->addDays(self::DIAS_PROXIMOS)->toDateString();
        $desde = $hoy->toDateString();

        $viajes = Reserva::propias()
            ->where('estado', 'activa')
            ->whereBetween('fecha_viaje_desde', [$desde, $hasta])
            ->with('alternativa.cotizacion.cliente:id,full_name')
            ->withCount('pasajeros')
            ->orderBy('fecha_viaje_desde')->orderBy('id')
            ->get();

        $salidas = SalidaOperativa::where('estado', 'activa')
            ->whereBetween('fecha', [$desde, $hasta])
            // Pasajeros = los de cada reserva enganchada, como el tablero de Salidas Operativas.
            ->with(['tourOrigen:id,nombre', 'guia:id,nombre', 'reservaItems' => fn ($q) => $q
                ->whereHas('reserva', fn ($r) => $r->where('estado', '!=', 'cancelada'))
                ->with(['reserva' => fn ($r) => $r->withCount('pasajeros')])])
            ->orderBy('fecha')->orderBy('hora')
            ->limit(self::MAXIMO_LISTA)
            ->get();

        $sinAsignar = AsignacionOperativa::itemsDelRango($desde, $hasta)
            ->with(['alternativaItem.proveedorTarifa.proveedorServicio.proveedor', 'guia', 'salidaOperativa.guia'])
            ->get()
            ->filter(static fn ($item): bool => AsignacionOperativa::sinAsignar($item))
            ->count();

        return [
            'hasta' => $hasta,
            'viajes' => $viajes->take(self::MAXIMO_LISTA)->map(static fn (Reserva $r): array => [
                'id' => $r->id,
                'codigo' => $r->codigo,
                'cliente' => $r->alternativa?->cotizacion?->cliente?->full_name,
                'desde' => $r->fecha_viaje_desde?->toDateString(),
                'hasta' => $r->fecha_viaje_hasta?->toDateString(),
                'pasajeros' => $r->pasajeros_count,
            ])->values()->all(),
            'viajes_total' => $viajes->count(),
            'salidas' => $salidas->map(static fn (SalidaOperativa $s): array => [
                'id' => $s->id,
                'fecha' => $s->fecha->toDateString(),
                'hora' => $s->hora ? substr((string) $s->hora, 0, 5) : null,
                'tour' => $s->tourOrigen?->nombre,
                'guia' => $s->guia?->nombre,
                'reservas' => ($reservas = $s->reservaItems->pluck('reserva')->filter()->unique('id'))->count(),
                'pasajeros' => $reservas->sum('pasajeros_count'),
                'cupo' => $s->cupo_maximo,
            ])->all(),
            'sin_asignar' => $sinAsignar,
        ];
    }

    // ── Aritmética entera ──

    /** Variación porcentual con un decimal ("12.5", "-3.0"); null si no hay base para comparar. */
    private static function variacion(int $actual, int $anterior): ?string
    {
        if ($anterior <= 0) {
            return null;
        }

        return self::dividirConUnDecimal(($actual - $anterior) * 1000, $anterior);
    }

    private static function porcentaje(int $parte, int $total): string
    {
        return $total > 0 ? self::dividirConUnDecimal($parte * 1000, $total) : '0.0';
    }

    /** $numerador / $denominador / 10 redondeado a un decimal, como texto, sin coma flotante. */
    private static function dividirConUnDecimal(int $numerador, int $denominador): string
    {
        $signo = $numerador < 0 ? '-' : '';
        $decimos = intdiv(abs($numerador) + intdiv($denominador, 2), $denominador);

        return sprintf('%s%d.%d', $signo, intdiv($decimos, 10), $decimos % 10);
    }
}
