<?php

namespace App\Services\Compras;

use App\Models\Compras\Compra;
use App\Models\Compras\PagoProveedor;
use App\Models\Proveedor;
use App\Services\BusquedaLike;
use App\Services\HoraPeru;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * T-137 — cuentas por pagar: a quién se le debe, cuánto, qué venció y qué vence pronto. Solo
 * lectura. No calcula saldos: salen de PagoProveedorService::saldos().
 *
 * Un grupo es una empresa (el documento actual de la ficha; sin documento, la ficha sola). Cada
 * cifra va por moneda y las monedas nunca se suman entre sí.
 */
class CuentasPorPagarService
{
    private const BLOQUE_SALDOS = 1000;

    private const COLUMNAS = ['pendiente', 'vencido', 'por_vencer', 'por_aprobar', 'a_cuenta', 'a_favor'];

    private const TRAMOS = ['d1_30', 'd31_60', 'd61_90', 'mas_90'];

    public function __construct(private PagoProveedorService $pagos) {}

    /**
     * Todos los grupos con alguna cifra distinta de cero (primero los que tienen algo vencido,
     * después por razón social) y los totales por moneda de esos grupos.
     *
     * @return array{grupos: list<array<string, mixed>>, totales: list<array<string, mixed>>}
     */
    public function resumen(int $dias, ?string $search = null, bool $soloVencido = false): array
    {
        $grupos = $this->agrupar($this->calcular(null, $dias));

        if ($search !== null && trim($search) !== '') {
            $claves = $this->clavesQueCoinciden(trim($search));
            $grupos = $grupos->filter(fn (array $g) => isset($claves[$g['clave']]));
        }
        if ($soloVencido) {
            $grupos = $grupos->filter(fn (array $g) => $g['tiene_vencido']);
        }

        $grupos = $grupos->sortBy([
            fn (array $a, array $b) => $b['tiene_vencido'] <=> $a['tiene_vencido'],
            fn (array $a, array $b) => mb_strtolower($a['razon_social']) <=> mb_strtolower($b['razon_social']),
            fn (array $a, array $b) => $a['clave'] <=> $b['clave'],
        ])->values();

        $totales = $this->sumarMonedas($grupos->pluck('cifras'));

        return [
            'grupos' => $grupos->map(fn (array $g) => $this->aTexto($g))->all(),
            'totales' => $this->monedasATexto($totales),
        ];
    }

    /**
     * Un grupo con sus compras de saldo distinto de cero y sus pagos con algo a cuenta o por
     * aprobar, por ficha. null si la clave no existe o no tiene nada.
     */
    public function detalle(string $clave, int $dias = 7): ?array
    {
        $ids = $this->proveedoresConMovimiento()
            ->filter(fn (Proveedor $p) => $p->claveDocumento() === $clave)
            ->modelKeys();
        if ($ids === []) {
            return null;
        }

        $datos = $this->calcular($ids, $dias);
        $grupo = $this->agrupar($datos)->first();
        if ($grupo === null) {
            return null;
        }

        $compras = $datos['compras']->filter(fn (Compra $c) => ! $datos['saldos'][$c->id]['saldo']->isZero());
        $estados = $this->pagos->estados($compras);
        $pagos = $this->pagosAbiertos($ids)->groupBy('proveedor_id');
        $compras = $compras->groupBy('proveedor_id');

        $grupo['fichas'] = array_map(fn (array $ficha) => $ficha + [
            'compras' => $compras->get($ficha['proveedor_id'], collect())
                ->map(fn (Compra $c) => $this->datosCompra($c, $datos['saldos'][$c->id], $estados[$c->id]))
                ->values()->all(),
            'pagos' => $pagos->get($ficha['proveedor_id'], collect())->values()->all(),
        ], $grupo['fichas']);

        return $this->aTexto($grupo);
    }

    /**
     * Las cifras de cada proveedor por moneda. La cantidad de consultas no depende de cuántas
     * compras o proveedores haya (salvo un bloque de saldos cada 1000 compras).
     */
    private function calcular(?array $proveedorIds, int $dias): array
    {
        $compras = Compra::vigentes()
            ->where('tipo_comprobante_codigo', '<>', Compra::TIPO_NOTA_CREDITO)
            ->when($proveedorIds !== null, fn ($q) => $q->whereIn('proveedor_id', $proveedorIds))
            ->orderBy('fecha_emision')->orderBy('id')
            ->get(['id', 'proveedor_id', 'tipo_comprobante_codigo', 'serie', 'numero', 'fecha_emision',
                'fecha_vencimiento', 'moneda', 'total', 'anulada_at']);

        // Por bloques: un tenant grande no debe pasar el límite de parámetros de PDO.
        $saldos = [];
        foreach (array_chunk($compras->modelKeys(), self::BLOQUE_SALDOS) as $bloque) {
            $saldos += $this->pagos->saldos($bloque);
        }

        $hoy = HoraPeru::hoyTexto();
        $limite = CarbonImmutable::parse($hoy)->addDays($dias)->toDateString();
        $cifras = [];

        foreach ($compras as $compra) {
            $saldo = $saldos[$compra->id]['saldo'];
            if ($saldo->isZero()) {
                continue;
            }
            $fila = &$cifras[$compra->proveedor_id][$compra->moneda];
            $fila ??= $this->enCero();

            if ($saldo->isNegative()) {
                $fila['a_favor'] = $fila['a_favor']->minus($saldo);
            } else {
                $fila['pendiente'] = $fila['pendiente']->plus($saldo);
                $vencimiento = $this->pagos->vencimiento($compra);
                if ($vencimiento < $hoy) {
                    $fila['vencido'] = $fila['vencido']->plus($saldo);
                    $tramo = $this->tramo($this->diasEntre($vencimiento, $hoy));
                    $fila[$tramo] = $fila[$tramo]->plus($saldo);
                } elseif ($vencimiento <= $limite) {
                    $fila['por_vencer'] = $fila['por_vencer']->plus($saldo);
                }
            }
            unset($fila);
        }

        foreach ($this->pagos->sinAplicarPorProveedor($proveedorIds) as $proveedorId => $monedas) {
            foreach ($monedas as $moneda => $montos) {
                if ($montos['a_cuenta']->isZero() && $montos['por_aprobar']->isZero()) {
                    continue;
                }
                $fila = &$cifras[$proveedorId][$moneda];
                $fila ??= $this->enCero();
                $fila['a_cuenta'] = $montos['a_cuenta'];
                $fila['por_aprobar'] = $montos['por_aprobar'];
                unset($fila);
            }
        }

        return ['compras' => $compras, 'saldos' => $saldos, 'cifras' => $cifras, 'hoy' => $hoy];
    }

    /** Junta las fichas por documento. */
    private function agrupar(array $datos): Collection
    {
        $cifras = $datos['cifras'];
        $proveedores = Proveedor::whereIn('id', array_keys($cifras))
            ->get(['id', 'razon_social', 'nombre_comercial', 'numero_documento', 'ciudad'])
            ->sortBy(fn (Proveedor $p) => [mb_strtolower($p->razon_social), mb_strtolower((string) $p->nombre_comercial), $p->id]);

        return $proveedores->groupBy(fn (Proveedor $p) => $p->claveDocumento())
            ->map(function (Collection $fichas, string $clave) use ($cifras) {
                $total = $this->sumarMonedas($fichas->map(fn (Proveedor $p) => $cifras[$p->id]));

                return [
                    'clave' => $clave,
                    'documento' => str_starts_with($clave, 'id:') ? null : $clave,
                    'razon_social' => $fichas->first()->razon_social,
                    'tiene_vencido' => collect($total)->contains(fn (array $m) => $m['vencido']->isPositive()),
                    'cifras' => $total,
                    'fichas' => $fichas->map(fn (Proveedor $p) => [
                        'proveedor_id' => $p->id,
                        'razon_social' => $p->razon_social,
                        'nombre_comercial' => $p->nombre_comercial,
                        'numero_documento' => $p->numero_documento,
                        'ciudad' => $p->ciudad,
                        'cifras' => $cifras[$p->id],
                    ])->values()->all(),
                ];
            })->values();
    }

    /**
     * Las claves de los grupos con alguna ficha (deba o no) que coincide con lo buscado.
     *
     * @return array<string, true>
     */
    private function clavesQueCoinciden(string $search): array
    {
        $patron = BusquedaLike::contiene($search);

        return Proveedor::where(fn ($q) => $q->where('razon_social', 'ilike', $patron)
            ->orWhere('nombre_comercial', 'ilike', $patron)
            ->orWhere('numero_documento', 'ilike', $patron))
            ->get(['id', 'numero_documento'])
            ->mapWithKeys(fn (Proveedor $p) => [$p->claveDocumento() => true])
            ->all();
    }

    private function proveedoresConMovimiento()
    {
        return Proveedor::whereIn('id', Compra::vigentes()->select('proveedor_id'))
            ->orWhereIn('id', PagoProveedor::where('estado', '<>', PagoProveedor::ANULADO)->select('proveedor_id'))
            ->get(['id', 'numero_documento']);
    }

    /** Pagos del grupo con algo a cuenta (vigentes) o esperando aprobación. */
    private function pagosAbiertos(array $proveedorIds): Collection
    {
        return PagoProveedor::whereIn('proveedor_id', $proveedorIds)
            ->where('estado', '<>', PagoProveedor::ANULADO)
            ->with(['metodoPago', 'cuentaBancaria'])
            ->withSum(['aplicaciones as aplicado' => fn ($q) => $q->whereNull('desaplicada_at')], 'monto')
            ->orderBy('fecha')->orderBy('id')
            ->get()
            ->map(function (PagoProveedor $p) {
                $pendiente = $p->estado === PagoProveedor::PENDIENTE;
                $aCuenta = $pendiente ? BigDecimal::of('0.00') : BigDecimal::of($p->monto)->minus((string) ($p->aplicado ?? '0'));
                if (! $pendiente && $aCuenta->isZero()) {
                    return null;
                }

                return [
                    'id' => $p->id,
                    'proveedor_id' => $p->proveedor_id,
                    'fecha' => $p->fecha->toDateString(),
                    'moneda' => $p->moneda,
                    'monto' => $p->monto,
                    'a_cuenta' => (string) $aCuenta->toScale(2),
                    'por_aprobar' => $pendiente ? $p->monto : '0.00',
                    'origen' => $p->origen,
                    'estado' => $p->estado,
                    'metodo_pago' => $p->metodoPago?->name,
                    'cuenta_bancaria' => $p->cuentaBancaria?->banco,
                    'numero_operacion' => $p->numero_operacion,
                ];
            })
            ->filter()
            ->values();
    }

    private function datosCompra(Compra $compra, array $saldo, array $estado): array
    {
        $hoy = HoraPeru::hoyTexto();
        $vencimiento = $this->pagos->vencimiento($compra);

        return [
            'id' => $compra->id,
            'proveedor_id' => $compra->proveedor_id,
            'tipo_comprobante_codigo' => $compra->tipo_comprobante_codigo,
            'serie' => $compra->serie,
            'numero' => $compra->numero,
            'fecha_emision' => $compra->fecha_emision->toDateString(),
            'vencimiento' => $vencimiento,
            'dias_atraso' => $vencimiento < $hoy && $saldo['saldo']->isPositive() ? $this->diasEntre($vencimiento, $hoy) : 0,
            'moneda' => $compra->moneda,
            'total' => $compra->total,
            'notas' => (string) $saldo['notas']->toScale(2),
            'aplicado' => (string) $saldo['aplicado']->toScale(2),
            'por_aprobar' => (string) $saldo['por_aprobar']->toScale(2),
            'saldo' => (string) $saldo['saldo']->toScale(2),
            'estado_pago' => $estado['estado_pago'],
        ];
    }

    private function diasEntre(string $desde, string $hasta): int
    {
        return (int) CarbonImmutable::parse($desde)->diffInDays(CarbonImmutable::parse($hasta), true);
    }

    private function tramo(int $atraso): string
    {
        return match (true) {
            $atraso <= 30 => 'd1_30',
            $atraso <= 60 => 'd31_60',
            $atraso <= 90 => 'd61_90',
            default => 'mas_90',
        };
    }

    private function enCero(): array
    {
        return array_fill_keys([...self::COLUMNAS, ...self::TRAMOS], BigDecimal::of('0.00'));
    }

    /** @param  Collection<int, array<string, array<string, BigDecimal>>>  $lista  cifras por moneda */
    private function sumarMonedas(Collection $lista): array
    {
        $total = [];
        foreach ($lista as $monedas) {
            foreach ($monedas as $moneda => $cifras) {
                $total[$moneda] ??= $this->enCero();
                foreach ($total[$moneda] as $columna => $suma) {
                    $total[$moneda][$columna] = $suma->plus($cifras[$columna]);
                }
            }
        }

        return $total;
    }

    /** Pasa las cifras a texto (soles primero) y calcula el neto. Recién acá se redondea. */
    private function monedasATexto(array $monedas): array
    {
        $orden = fn (string $moneda) => $moneda === 'PEN' ? '' : $moneda;
        uksort($monedas, fn (string $a, string $b) => $orden($a) <=> $orden($b));

        $texto = fn (BigDecimal $monto) => (string) $monto->toScale(2);

        return array_values(array_map(fn (string $moneda, array $c) => [
            'moneda' => $moneda,
            'pendiente' => $texto($c['pendiente']),
            'vencido' => $texto($c['vencido']),
            'por_vencer' => $texto($c['por_vencer']),
            'antiguedad' => array_combine(self::TRAMOS, array_map(fn (string $t) => $texto($c[$t]), self::TRAMOS)),
            'por_aprobar' => $texto($c['por_aprobar']),
            'a_cuenta' => $texto($c['a_cuenta']),
            'a_favor' => $texto($c['a_favor']),
            'neto' => $texto($c['pendiente']->minus($c['a_cuenta'])->minus($c['a_favor'])),
        ], array_keys($monedas), $monedas));
    }

    private function aTexto(array $grupo): array
    {
        $conMonedas = function (array $fila) {
            $fila['monedas'] = $this->monedasATexto($fila['cifras']);
            unset($fila['cifras']);

            return $fila;
        };
        $grupo['fichas'] = array_map($conMonedas, $grupo['fichas']);

        return $conMonedas($grupo);
    }
}
