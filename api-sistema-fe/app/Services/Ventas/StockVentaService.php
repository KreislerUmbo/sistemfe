<?php

namespace App\Services\Ventas;

use App\Models\Product\Product;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * T-113 — Stock de los productos al guardar, editar o eliminar una venta, y al reponer por NC.
 *
 * Debe usarse DENTRO de una transacción: bloquea los productos con lockForUpdate() hasta que
 * ella termina. El orden por `id` evita que dos ventas con los mismos productos en distinto
 * orden se bloqueen entre sí (deadlock). Solo se mueve el stock de los productos que
 * `Product::controlaStock()` dice; los demás (servicios, «venta sin stock», controla_stock=false)
 * no se validan ni se tocan.
 */
class StockVentaService
{
    /** Vende: valida que alcance y descuenta. 422 si algún producto no alcanza. */
    public function descontar(iterable $lineas): void
    {
        $this->aplicar($this->agrupar($lineas, 1));
    }

    /** Devuelve al stock (venta eliminada, NC con reposición). Nunca falla por falta de stock. */
    public function devolver(iterable $lineas): void
    {
        $this->aplicar($this->agrupar($lineas, -1));
    }

    /**
     * Edición de una venta: solo se mueve la diferencia por producto (lo nuevo menos lo que ya
     * tenía), así editar sin cambiar cantidades no exige stock y bajar una cantidad lo devuelve.
     */
    public function cambiar(iterable $antes, iterable $despues): void
    {
        $delta = $this->agrupar($despues, 1);
        foreach ($this->agrupar($antes, -1) as $productoId => $cantidad) {
            $delta[$productoId] = ($delta[$productoId] ?? 0) + $cantidad;
        }
        $this->aplicar($delta);
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

    /** @param array<int, float> $delta product_id => cantidad con signo (+ descuenta, − devuelve) */
    private function aplicar(array $delta): void
    {
        $delta = array_filter($delta, fn ($d) => abs($d) > 1e-9);
        if (!$delta) {
            return;
        }

        ksort($delta); // orden por id: mismo orden de bloqueo en todas las ventas

        $productos = Product::withTrashed()
            ->whereIn('id', array_keys($delta))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($delta as $productoId => $cambio) {
            $producto = $productos->get($productoId);
            if (!$producto || !$producto->controlaStock()) {
                continue;
            }

            if ($cambio > 0 && round((float) $producto->stock - $cambio, 6) < 0) {
                $quedan = max(0, (float) $producto->stock);
                throw new HttpException(
                    422,
                    'Solo quedan ' . rtrim(rtrim(number_format($quedan, 4, '.', ''), '0'), '.') .
                    ' unidades de «' . $producto->title . '».'
                );
            }
        }

        foreach ($delta as $productoId => $cambio) {
            $producto = $productos->get($productoId);
            if (!$producto || !$producto->controlaStock()) {
                continue;
            }
            // stock - cambio: cambio > 0 descuenta, cambio < 0 devuelve
            $producto->decrement('stock', $cambio);
        }
    }
}
