<?php

namespace App\Services\Stock;

use App\Models\Compras\Compra;
use App\Models\Product\Product;
use App\Models\Product\StockMovimiento;
use App\Models\Sale\Note;
use App\Models\Sale\Sale;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * T-113 / T-153 — El único que mueve el stock de los productos, y quien escribe el kárdex.
 *
 * Cada operación corre en una transacción (un savepoint si el llamador ya tiene una), con los
 * productos bloqueados por lockForUpdate(): la fila del kárdex y su saldo salen del stock
 * bloqueado. El orden por `id` evita que dos operaciones con los mismos productos en distinto
 * orden se bloqueen entre sí (deadlock). Solo escriben kárdex y validan existencias los productos
 * que `Product::controlaStock()` dice.
 */
class StockService
{
    /** Vende: valida que alcance y descuenta. 422 si algún producto no alcanza. */
    public function vender(Sale $venta, iterable $lineas): void
    {
        $this->aplicar($this->agrupar($lineas, 1), StockMovimiento::VENTA, 'venta', $venta->id);
    }

    /**
     * Edición de una venta: solo se mueve la diferencia por producto (lo nuevo menos lo que ya
     * tenía), así editar sin cambiar cantidades no exige stock y bajar una cantidad lo devuelve.
     */
    public function cambiarVenta(Sale $venta, iterable $antes, iterable $despues): void
    {
        $delta = $this->agrupar($despues, 1);
        foreach ($this->agrupar($antes, -1) as $productoId => $cantidad) {
            $delta[$productoId] = ($delta[$productoId] ?? 0) + $cantidad;
        }
        $this->aplicar($delta, StockMovimiento::VENTA_EDICION, 'venta', $venta->id);
    }

    /** Venta eliminada: sus bienes vuelven al stock. Nunca falla por falta de stock. */
    public function devolverVentaEliminada(Sale $venta, iterable $lineas): void
    {
        $this->aplicar($this->agrupar($lineas, -1), StockMovimiento::VENTA_ELIMINADA, 'venta', $venta->id);
    }

    /** Nota de Crédito con reposición. */
    public function devolverPorNota(Note $nota, iterable $lineas): void
    {
        $this->aplicar($this->agrupar($lineas, -1), StockMovimiento::NC_VENTA, 'nota', $nota->id);
    }

    /** T-154: las líneas de una compra entran al stock y al costo promedio. */
    public function entrarPorCompra(Compra $compra, iterable $lineas): void
    {
        [$unidades, $costos] = $this->agruparCompra($lineas);
        $this->aplicar($this->negar($unidades), StockMovimiento::COMPRA, 'compra', $compra->id, $costos);
    }

    /** Anulación de una compra: sale lo que entró y se quita del promedio. 422 si ya no alcanza. */
    public function revertirCompra(Compra $compra, iterable $lineas): void
    {
        [$unidades, $costos] = $this->agruparCompra($lineas);
        // Solo lo que de verdad se movió: un producto pudo empezar a controlar stock después.
        $unidades = array_intersect_key($unidades, $this->movidos($compra, StockMovimiento::COMPRA));
        $this->aplicar($unidades, StockMovimiento::COMPRA_ANULADA, 'compra', $compra->id, $costos);
    }

    /** NC de compra: devolución al proveedor, al costo promedio vigente (que no cambia). */
    public function salirPorNotaCompra(Compra $nota, iterable $lineas): void
    {
        [$unidades] = $this->agruparCompra($lineas);
        $this->aplicar($unidades, StockMovimiento::NC_COMPRA, 'compra', $nota->id);
    }

    /** Anulación de una NC de compra: las unidades vuelven al costo con que salieron. */
    public function revertirNotaCompra(Compra $nota, iterable $lineas): void
    {
        $salidas = $this->movidos($nota, StockMovimiento::NC_COMPRA);
        [$unidades] = $this->agruparCompra($lineas);
        $unidades = array_intersect_key($unidades, $salidas);

        $costos = [];
        foreach ($unidades as $productoId => $cantidad) {
            if ($salidas[$productoId] !== null) {
                $unitario = BigDecimal::of((string) $salidas[$productoId]);
                $costos[$productoId] = ['unitario' => $unitario, 'valor' => $unitario->multipliedBy((string) $cantidad)];
            }
        }
        $this->aplicar($this->negar($unidades), StockMovimiento::NC_COMPRA_ANULADA, 'compra', $nota->id, $costos);
    }

    /** @return array<int, string|null> product_id => costo unitario de la fila de ese tipo */
    private function movidos(Compra $compra, string $tipo): array
    {
        return StockMovimiento::where('documento_tipo', 'compra')
            ->where('documento_id', $compra->id)
            ->where('tipo', $tipo)
            ->toBase()
            ->pluck('costo_unitario', 'product_id')
            ->all();
    }

    /**
     * Unidades base y costo por producto de las líneas de una compra (varias líneas se suman).
     *
     * @return array{0: array<int, float>, 1: array<int, array{unitario: BigDecimal, valor: BigDecimal}>}
     */
    private function agruparCompra(iterable $lineas): array
    {
        $sumas = [];
        foreach ($lineas as $linea) {
            $id = (int) $linea['product_id'];
            $q = BigDecimal::of((string) $linea['cantidad'])->multipliedBy((string) $linea['factor']);
            [$unidades, $valor] = $sumas[$id] ?? [BigDecimal::zero(), BigDecimal::zero()];
            $sumas[$id] = [$unidades->plus($q), $valor->plus($q->multipliedBy((string) $linea['costo_unitario_base']))];
        }

        $delta = $costos = [];
        foreach ($sumas as $id => [$unidades, $valor]) {
            $delta[$id] = (float) (string) $unidades->toScale(6, RoundingMode::HALF_UP);
            $costos[$id] = ['unitario' => $valor->dividedBy($unidades, 6, RoundingMode::HALF_UP), 'valor' => $valor];
        }

        return [$delta, $costos];
    }

    /** @param array<int, float> $unidades */
    private function negar(array $unidades): array
    {
        return array_map(fn (float $q) => -$q, $unidades);
    }

    /**
     * Fija el stock de un producto desde su ficha. En un producto que controla stock es un
     * `ajuste`: cambiarlo exige motivo y deja fila en el kárdex. En los demás solo se guarda.
     *
     * $inicioDeControl: el producto acaba de crearse o de empezar a controlar stock; deja su fila
     * aunque la cantidad no cambie, contada desde el último saldo del kárdex (0 si no tiene).
     */
    public function ajustar(int $productoId, float $stockNuevo, ?string $motivo, bool $inicioDeControl = false): void
    {
        DB::transaction(function () use ($productoId, $stockNuevo, $motivo, $inicioDeControl) {
            $producto = Product::withTrashed()->lockForUpdate()->findOrFail($productoId);
            $cambia = round($stockNuevo - (float) $producto->stock, 6) != 0;
            $controla = $producto->controlaStock();
            $motivo = trim((string) $motivo);

            if (!$cambia && !($controla && $inicioDeControl)) {
                return;
            }
            if ($controla && $cambia && $motivo === '') {
                throw new HttpException(422, 'Indica el motivo del ajuste de stock.');
            }

            $base = (float) $producto->stock;
            if ($controla && $inicioDeControl) {
                $base = (float) StockMovimiento::where('product_id', $productoId)->orderByDesc('id')->value('saldo');
            }

            $this->guardarStock($producto, $stockNuevo);

            if ($controla) {
                $this->anotar(
                    $producto->id,
                    StockMovimiento::AJUSTE,
                    $stockNuevo - $base,
                    $stockNuevo,
                    motivo: $motivo !== '' ? $motivo : 'empieza a controlar stock',
                );
            }
        });
    }

    /**
     * Suma las cantidades por producto (T-112: varias líneas del mismo producto) con el signo dado.
     * Cada línea es un array o un modelo con `product_id` (o `product.id`) y `quantity`.
     *
     * @return array<int, float> product_id => cantidad con signo (+ descuenta, − devuelve)
     */
    private function agrupar(iterable $lineas, int $signo): array
    {
        $porProducto = [];
        foreach ($lineas as $linea) {
            $productoId = (int) (is_array($linea)
                ? ($linea['product_id'] ?? $linea['product']['id'] ?? 0)
                : $linea->product_id);
            $cantidad = (float) (is_array($linea) ? $linea['quantity'] : $linea->quantity);
            if ($productoId <= 0) {
                continue;
            }
            $porProducto[$productoId] = ($porProducto[$productoId] ?? 0) + $signo * $cantidad;
        }

        return $porProducto;
    }

    /**
     * @param array<int, float> $delta product_id => cantidad con signo (+ descuenta, − devuelve)
     * @param array<int, array{unitario: BigDecimal, valor: BigDecimal}> $costos solo compras (T-154)
     */
    private function aplicar(array $delta, string $tipo, string $documentoTipo, int $documentoId, array $costos = []): void
    {
        $delta = array_filter($delta, fn ($d) => abs($d) > 1e-9);
        if (!$delta) {
            return;
        }

        ksort($delta); // orden por id: mismo orden de bloqueo en todas las ventas

        DB::transaction(function () use ($delta, $tipo, $documentoTipo, $documentoId, $costos) {
            $productos = Product::withTrashed()
                ->whereIn('id', array_keys($delta))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id')
                ->filter(fn (Product $p) => $p->controlaStock());

            foreach ($delta as $productoId => $cambio) {
                $producto = $productos->get($productoId);
                if ($producto && $cambio > 0 && round((float) $producto->stock - $cambio, 6) < 0) {
                    $mensaje = 'Solo quedan ' . $this->numero(max(0, (float) $producto->stock)) .
                        ' unidades de «' . $producto->title . '».';
                    if ($tipo === StockMovimiento::COMPRA_ANULADA) {
                        $mensaje .= ' La compra sumó ' . $this->numero($cambio) . ', así que no se puede anular: ' .
                            'registra una nota de crédito del proveedor (devolución) o haz un ajuste de stock.';
                    }
                    throw new HttpException(422, $mensaje);
                }
            }

            foreach ($delta as $productoId => $cambio) {
                $producto = $productos->get($productoId);
                if (!$producto) {
                    continue;
                }
                // stock - cambio: cambio > 0 descuenta, cambio < 0 devuelve
                $saldo = round((float) $producto->stock - $cambio, 6);
                // El costo usa el stock y el promedio de ANTES, bajo el mismo bloqueo.
                $costo = $this->costear($producto, $tipo, -$cambio, $costos[$productoId] ?? null);
                $this->guardarStock($producto, $saldo);
                $this->anotar($producto->id, $tipo, -$cambio, $saldo, $documentoTipo, $documentoId, costo: $costo);
            }
        });
    }

    /**
     * T-154 (D7) — actualiza `costo_promedio` en el modelo (lo guarda guardarStock()) y devuelve el
     * costo unitario de la fila del kárdex. Fuera de compras no hace nada.
     *
     * @param float $cantidad con signo: + entra, − sale
     * @param array{unitario: BigDecimal, valor: BigDecimal}|null $costo
     */
    private function costear(Product $producto, string $tipo, float $cantidad, ?array $costo): ?string
    {
        $promedio = $producto->costo_promedio;

        // La devolución al proveedor sale al promedio vigente, que no cambia.
        if ($tipo === StockMovimiento::NC_COMPRA) {
            return $promedio;
        }
        if ($costo === null) {
            return null;
        }

        $stock = BigDecimal::of((string) round((float) $producto->stock, 6));
        $resto = $stock->plus((string) round($cantidad, 6)); // stock después

        if ($tipo === StockMovimiento::COMPRA_ANULADA) {
            // Sin resto o con resultado <= 0, el promedio se queda como está.
            if ($promedio !== null && $resto->isPositive()) {
                $nuevo = $stock->multipliedBy($promedio)->minus($costo['valor'])
                    ->dividedBy($resto, 6, RoundingMode::HALF_UP);
                if ($nuevo->isPositive()) {
                    $producto->costo_promedio = (string) $nuevo;
                }
            }
        } elseif (!$stock->isPositive() || $promedio === null) {
            $producto->costo_promedio = (string) $costo['unitario'];
        } else {
            $producto->costo_promedio = (string) $stock->multipliedBy($promedio)->plus($costo['valor'])
                ->dividedBy($resto, 6, RoundingMode::HALF_UP);
        }

        return (string) $costo['unitario'];
    }

    private function numero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 4, '.', ''), '0'), '.');
    }

    private function guardarStock(Product $producto, float $stock): void
    {
        $producto->stock = $stock;
        $producto->save();
    }

    private function anotar(
        int $productoId,
        string $tipo,
        float $cantidad,
        float $saldo,
        ?string $documentoTipo = null,
        ?int $documentoId = null,
        ?string $motivo = null,
        ?string $costo = null,
    ): void {
        StockMovimiento::create([
            'product_id'     => $productoId,
            'tipo'           => $tipo,
            'cantidad'       => round($cantidad, 6),
            'costo_unitario' => $costo,
            'saldo'          => round($saldo, 6),
            'documento_tipo' => $documentoTipo,
            'documento_id'   => $documentoId,
            'motivo'         => $motivo,
            'user_id'        => auth('api')->id(),
        ]);
    }
}
