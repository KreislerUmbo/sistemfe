<?php

namespace App\Services\Ventas;

use App\Models\Client\Client;
use App\Models\CommercialQuote\CommercialQuote;
use App\Models\Product\Product;
use App\Models\Product\TaxConfig;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Symfony\Component\HttpKernel\Exception\HttpException;

// T-109 — Sin el permiso `cambiar_precio_venta`, el precio de cada línea tiene que ser el que
// el sistema habría puesto solo. Es la misma cuenta que hace register.vue (precioBaseDe() →
// getPrecioBaseSinIgv()): el front manda `price_base` SIN IGV, calculado así:
//   - precio de catálogo: price_company si el cliente es empresa (type_client = 2) y
//     price_company > 0; si no, price_general;
//   - si el producto trae el IGV incluido (include_igv), se divide entre (1 + tasa/100); la tasa es
//     la del IVAP si is_ivap = 2, 0 si el producto es exonerado/inafecto (20/30) y el IGV general
//     (tax_configs, es_default; 18 si no hay) en el resto;
//   - sin conversión de moneda: el mismo precio se manda en PEN o en USD.
// Una línea también vale si su precio coincide con la misma línea de la cotización comercial de
// la que sale la venta (decisión del 19-nov), siempre que sea del mismo cliente y convertible.
// Todo en BigDecimal (sin floats) y con tolerancia de un céntimo, porque el front manda el
// resultado de una división sin redondear.
class PrecioVentaValidator
{
    private const TOLERANCIA = '0.01';

    public const MENSAJE = 'No tienes permiso para cambiar el precio.';

    private ?BigDecimal $tasaIgv = null;
    private ?BigDecimal $tasaIvap = null;

    /**
     * @param array<int, array> $detalles  sale_details del payload
     * @param array<int|string, mixed> $preciosGuardados  price_base ya guardado, por id de detalle
     *        (update: una línea que no cambió de precio no se rechaza)
     */
    public function validar(array $detalles, ?Client $cliente, ?CommercialQuote $cotizacion = null, array $preciosGuardados = []): void
    {
        $ids = collect($detalles)->map(fn ($d) => $d['product']['id'] ?? null)->filter()->unique()->all();
        $productos = Product::whereIn('id', $ids)->get()->keyBy('id');
        $preciosCotizados = $this->preciosCotizados($cotizacion);

        foreach ($detalles as $i => $detalle) {
            $enviado = $this->decimal($detalle['price_base'] ?? null);
            if ($enviado === null) {
                continue; // el formato del payload no es de este validador
            }

            $idDetalle = $detalle['id'] ?? null;
            if ($idDetalle && isset($preciosGuardados[$idDetalle])
                && $this->coincide($enviado, $this->decimal($preciosGuardados[$idDetalle]), '0.0001')) {
                continue; // no tocó el precio de una línea que ya existía
            }

            $producto = $productos->get($detalle['product']['id'] ?? null);
            if (!$producto) {
                continue; // un producto inexistente lo rechaza el resto del flujo
            }

            if ($this->coincide($enviado, $this->precioBaseCatalogo($producto, $cliente), self::TOLERANCIA)) {
                continue;
            }
            foreach ($preciosCotizados[$producto->id] ?? [] as $cotizado) {
                if ($this->coincide($enviado, $cotizado, self::TOLERANCIA)) {
                    continue 2;
                }
            }

            // Mismo estilo que el resto de validadores de SaleController: 422 con `message`.
            throw new HttpException(422, self::MENSAJE . ' La línea ' . ($i + 1) . ' no tiene el precio que le corresponde.');
        }
    }

    /**
     * Cotización que vale como origen del precio: del mismo cliente y todavía convertible
     * (mismas condiciones que CommercialQuoteController::paraVenta()). null si no aplica.
     */
    public function cotizacionValida(mixed $cotizacionId, ?Client $cliente): ?CommercialQuote
    {
        if (!$cotizacionId || !is_numeric($cotizacionId) || !$cliente) {
            return null;
        }
        $cotizacion = CommercialQuote::with('items')->find((int) $cotizacionId);
        if (!$cotizacion
            || (int) $cotizacion->client_id !== (int) $cliente->id
            || $cotizacion->converted_sale_id
            || in_array($cotizacion->status, ['anulada', 'rechazada', 'vencida'], true)) {
            return null;
        }
        return $cotizacion;
    }

    /** @return array<int, BigDecimal[]> precios cotizados por product_id (sin IGV: el front los usa tal cual) */
    private function preciosCotizados(?CommercialQuote $cotizacion): array
    {
        $porProducto = [];
        foreach ($cotizacion?->items ?? [] as $item) {
            if ($item->product_id) {
                $porProducto[$item->product_id][] = BigDecimal::of((string) $item->unit_price);
            }
        }
        return $porProducto;
    }

    private function precioBaseCatalogo(Product $producto, ?Client $cliente): BigDecimal
    {
        $empresa = $cliente && (string) $cliente->type_client === '2' && (float) $producto->price_company > 0;
        $bruto = BigDecimal::of((string) ($empresa ? $producto->price_company : $producto->price_general));

        if (!$producto->include_igv) {
            return $bruto;
        }
        $divisor = BigDecimal::one()->plus($this->tasaDe($producto)->dividedBy(100, 10, RoundingMode::HALF_UP));
        return $bruto->dividedBy($divisor, 10, RoundingMode::HALF_UP);
    }

    // Misma regla que getTasaProductoBase() del front.
    private function tasaDe(Product $producto): BigDecimal
    {
        if ((int) $producto->is_ivap === 2) {
            return $this->tasaIvap ??= $this->tasa('IVAP', false, '4');
        }
        if (in_array($producto->tip_afe_igv_default ?? '10', ['20', '30'], true)) {
            return BigDecimal::zero();
        }
        return $this->tasaIgv ??= $this->tasa('IGV', true, '18');
    }

    private function tasa(string $tipo, bool $soloDefault, string $porDefecto): BigDecimal
    {
        $consulta = TaxConfig::where('tipo', $tipo)->where('es_activo', true);
        if ($soloDefault) {
            $consulta->where('es_default', true);
        }
        return BigDecimal::of((string) ($consulta->value('tasa_porcentaje') ?? $porDefecto));
    }

    private function coincide(BigDecimal $a, ?BigDecimal $b, string $tolerancia): bool
    {
        return $b !== null && $a->minus($b)->abs()->isLessThanOrEqualTo($tolerancia);
    }

    private function decimal(mixed $valor): ?BigDecimal
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return null;
        }
        return BigDecimal::of(is_float($valor) ? sprintf('%.10F', $valor) : (string) $valor);
    }
}
