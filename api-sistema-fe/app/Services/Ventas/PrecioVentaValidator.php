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
// el sistema habría puesto solo, y los importes de la línea tienen que salir de ese precio.
//
// Precio: la misma cuenta que hace register.vue (precioBaseDe() → getPrecioBaseSinIgv()); el front
// manda `price_base` SIN IGV, calculado así:
//   - precio de catálogo: price_company si el cliente es empresa (type_client = 2) y
//     price_company > 0; si no, price_general;
//   - si el producto trae el IGV incluido (include_igv), se divide entre (1 + tasa/100); la tasa es
//     la del IVAP si is_ivap = 2, 0 si el producto es exonerado/inafecto (20/30) y el IGV general
//     (tax_configs, es_default; 18 si no hay) en el resto;
//   - sin conversión de moneda: el mismo precio se manda en PEN o en USD;
//   - catálogo en 0 = producto «a precio variable»: no se compara.
// Una línea también vale si su precio coincide con la misma línea de la cotización comercial de
// la que sale la venta (decisión del 19-nov), siempre que sea del mismo cliente y convertible.
//
// Importes: la misma cuenta que updateItem() de register.vue (ver importesIncoherentes()).
//
// Todo en BigDecimal (sin floats) y con tolerancia de céntimos, porque el front manda el
// resultado de divisiones sin redondear.
class PrecioVentaValidator
{
    private const TOLERANCIA = '0.01';

    public const MENSAJE = 'No tienes permiso para cambiar el precio.';

    private ?BigDecimal $tasaIgv = null;
    private ?BigDecimal $tasaIvap = null;

    /**
     * @param array<int, array> $detalles  sale_details del payload
     * @param array<int|string, array{price_base: mixed, quantity: mixed, discount: mixed, tip_afe_igv: mixed}> $lineasGuardadas
     *        lo ya guardado por id de detalle (update): el precio que no cambió no se rechaza, y una
     *        línea que no cambió en nada (precio, cantidad, descuento, afectación) tampoco se revisa
     */
    public function validar(array $detalles, ?Client $cliente, ?CommercialQuote $cotizacion = null, array $lineasGuardadas = []): void
    {
        $ids = collect($detalles)->map(fn ($d) => $d['product']['id'] ?? null)->filter()->unique()->all();
        $productos = Product::whereIn('id', $ids)->get()->keyBy('id');
        $preciosCotizados = $this->preciosCotizados($cotizacion);

        foreach ($detalles as $i => $detalle) {
            $enviado = $this->decimal($detalle['price_base'] ?? null);
            $producto = $productos->get($detalle['product']['id'] ?? null);
            if ($enviado === null || !$producto) {
                continue; // formato del payload o producto inexistente: lo rechaza el resto del flujo
            }

            $guardada = ($detalle['id'] ?? null) ? ($lineasGuardadas[$detalle['id']] ?? null) : null;

            if (!$this->precioValido($enviado, $producto, $cliente, $preciosCotizados[$producto->id] ?? [], $guardada)) {
                // Mismo estilo que el resto de validadores de SaleController: 422 con `message`.
                throw new HttpException(422, self::MENSAJE . ' La línea ' . ($i + 1) . ' no tiene el precio que le corresponde.');
            }

            if (!$this->lineaSinCambios($detalle, $guardada)) {
                $motivo = $this->importesIncoherentes($detalle, $producto);
                if ($motivo !== null) {
                    throw new HttpException(422, self::MENSAJE . ' La línea ' . ($i + 1) . ": $motivo");
                }
            }
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

    /** @param BigDecimal[] $cotizados */
    private function precioValido(BigDecimal $enviado, Product $producto, ?Client $cliente, array $cotizados, ?array $guardada): bool
    {
        if ($guardada && $this->coincide($enviado, $this->decimal($guardada['price_base']), '0.0001')) {
            return true; // no tocó el precio de una línea que ya existía
        }

        $catalogo = $this->precioBaseCatalogo($producto, $cliente);
        // Catálogo en 0 = producto «a precio variable» (p. ej. los genéricos de Agencia): el
        // precio siempre se escribe a mano, no hay precio del sistema contra el cual comparar.
        if ($catalogo->isZero() || $this->coincide($enviado, $catalogo, self::TOLERANCIA)) {
            return true;
        }
        foreach ($cotizados as $cotizado) {
            if ($this->coincide($enviado, $cotizado, self::TOLERANCIA)) {
                return true;
            }
        }

        return false;
    }

    private function lineaSinCambios(array $detalle, ?array $guardada): bool
    {
        if (!$guardada) {
            return false;
        }
        $igual = fn (string $campo) => $this->coincide(
            $this->decimal($detalle[$campo] ?? null) ?? BigDecimal::of('-1'),
            $this->decimal($guardada[$campo] ?? null),
            '0.0001'
        );

        return $igual('price_base') && $igual('quantity') && $igual('discount')
            && (string) ($detalle['tip_afe_igv'] ?? '') === (string) ($guardada['tip_afe_igv'] ?? '');
    }

    /**
     * Los importes de la línea tienen que salir de price_base, cantidad y descuento con la MISMA
     * cuenta que updateItem() de register.vue; si no, bastaría mandar el price_base correcto con
     * montos menores. Devuelve el motivo, o null si cuadra.
     * La afectación IGV de la línea es libre (decisión del 18-nov): solo se exige que la tasa
     * coincida con la afectación elegida (10/11 → IGV, 17 → IVAP, el resto → 0).
     */
    private function importesIncoherentes(array $d, Product $producto): ?string
    {
        $cantidad = $this->decimal($d['quantity'] ?? null);
        $precio = $this->decimal($d['price_base'] ?? null);
        $descuento = $this->decimal($d['discount'] ?? 0) ?? BigDecimal::zero();
        if (!$cantidad || !$precio || $cantidad->isLessThanOrEqualTo(0)) {
            return 'la cantidad no es válida.';
        }

        $valorVenta = $cantidad->multipliedBy($precio);

        // Descuento: solo si el producto lo admite (is_discount = 2; la pantalla ni muestra el botón
        // en los demás), y entre 0 y su máximo (la pantalla ya lo limita con max_discount).
        if ((int) $producto->is_discount !== 2 && !$descuento->isZero()) {
            return 'este producto no admite descuento.';
        }
        $maximo = $valorVenta->multipliedBy((string) ((float) $producto->max_discount))->dividedBy(100, 6, RoundingMode::HALF_UP);
        if ($descuento->isNegative() || $descuento->isGreaterThan($maximo->plus(self::TOLERANCIA))) {
            return 'el descuento supera el máximo permitido del producto.';
        }
        $base = $valorVenta->minus($descuento);

        $isc = $this->iscDe($producto, $cantidad, $precio, $base);

        $tasa = match ((string) ($d['tip_afe_igv'] ?? '')) {
            '10', '11' => $this->tasaIgv ??= $this->tasa('IGV', true, '18'),
            '17' => $this->tasaIvap ??= $this->tasa('IVAP', false, '4'),
            default => BigDecimal::zero(),
        };
        $igv = $base->plus($isc)->multipliedBy($tasa)->dividedBy(100, 6, RoundingMode::HALF_UP);

        $icbper = BigDecimal::zero();
        if ((int) $producto->is_icbper === 2) {
            $porBolsa = TaxConfig::where('tipo', 'ICBPER')->where('es_activo', true)->orderBy('id')->value('tasa_porcentaje');
            $icbper = $cantidad->multipliedBy((string) ($porBolsa ?? '0.50'));
        }

        $impuestos = $igv->plus($isc)->plus($icbper);
        $total = $base->plus($impuestos);

        // Tolerancia por línea (no por unidad): el front redondea cada importe a 4 decimales y
        // price_final es unitario, así que su error se multiplica por la cantidad.
        $tolUnitario = BigDecimal::of(self::TOLERANCIA)->plus($cantidad->multipliedBy('0.00005'));
        $revisar = [
            'el valor de venta' => [$valorVenta, $d['mto_valor_venta'] ?? null, self::TOLERANCIA],
            'la base del IGV' => [$base, $d['mto_base_igv'] ?? null, self::TOLERANCIA],
            'el subtotal' => [$base, $d['subtotal'] ?? null, self::TOLERANCIA],
            'el IGV' => [$igv, $d['igv'] ?? null, self::TOLERANCIA],
            'el ISC' => [$isc, $d['isc'] ?? 0, self::TOLERANCIA],
            'el ICBPER' => [$icbper, $d['icbper'] ?? 0, self::TOLERANCIA],
            'el total de impuestos' => [$impuestos, $d['total_impuestos'] ?? null, self::TOLERANCIA],
            'el porcentaje de IGV' => [$tasa, $d['porcentaje_igv'] ?? null, '0.0001'],
        ];
        foreach ($revisar as $que => [$esperado, $enviado, $tolerancia]) {
            if (!$this->coincide($esperado, $this->decimal($enviado), $tolerancia)) {
                return "$que no coincide con el precio y la cantidad.";
            }
        }
        $precioFinal = $this->decimal($d['price_final'] ?? null);
        if (!$precioFinal || !$this->coincide($total, $precioFinal->multipliedBy($cantidad), (string) $tolUnitario)) {
            return 'el precio final no coincide con el precio y la cantidad.';
        }

        return null;
    }

    // ISC como en updateItem(): solo si el producto es is_isc y tiene porcentaje o monto fijo.
    // 01 al valor (% sobre la base) · 02 específico (cantidad × litros × monto fijo) · 03 al valor según PVP.
    private function iscDe(Product $producto, BigDecimal $cantidad, BigDecimal $precio, BigDecimal $base): BigDecimal
    {
        $porcentaje = BigDecimal::of((string) ((float) $producto->percentage_isc));
        $fijo = BigDecimal::of((string) ((float) $producto->monto_isc_fijo));
        if (!$producto->is_isc || ($porcentaje->isLessThanOrEqualTo(0) && $fijo->isLessThanOrEqualTo(0))) {
            return BigDecimal::zero();
        }

        $litros = (float) $producto->contenido_neto_litros > 0 ? (string) ((float) $producto->contenido_neto_litros) : '1';

        return match ($producto->tipo_isc ?? '01') {
            '01' => $base->multipliedBy($porcentaje)->dividedBy(100, 6, RoundingMode::HALF_UP),
            '02' => $cantidad->multipliedBy($litros)->multipliedBy($fijo),
            '03' => $precio->multipliedBy($cantidad)->multipliedBy($porcentaje)->dividedBy(100, 6, RoundingMode::HALF_UP),
            default => BigDecimal::zero(),
        };
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
