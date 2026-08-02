<?php

namespace App\Services\AgenciaViajes;

// Motor de precios único del vertical — plan-modulo-cotizaciones-reservas.md
// §2.5. Servicio de dominio puro (sin Request/Response), para que sea
// testeable y reusable entre AlternativaItemController (proveedor/
// pasaje_aereo) y cualquier otro punto que necesite aplicar margen +
// validar el piso, sin duplicar la fórmula.
//
// Revisado antes de escribir esto: ProveedorTarifaController (Sesión 11a)
// NO calcula margen inline — precio_venta_adulto/nino/infante los ingresa
// el admin directo al cargar la tarifa de catálogo (son precios de venta
// ya decididos, no una cotización puntual). Nada que refactorizar ahí.
class PriceEngineService
{
    /**
     * @param  float  $costoBase  Costo del proveedor/tarifa antes de cargos y margen.
     * @param  array<int, array{codigo?: string, nombre: string, monto: float, tipo?: string}>  $cargos
     *         Montos que se suman dólar a dólar tanto al costo como a la venta —
     *         pass-through de terceros (impuestos, tasas), NUNCA se les aplica margen.
     * @param  string  $margenTipo  'porcentaje' | 'fijo'
     * @param  float  $margenValor
     * @param  float|null  $descuentoMaximoPct  Piso #1 — % máximo que se puede descontar de venta_base.
     * @param  float|null  $margenMinimoPct  Piso #2 — % mínimo de utilidad sobre costo_base, protegido
     *         aunque se aplique descuento_maximo_pct.
     * @return array{
     *     venta_base: float,
     *     venta_total: float,
     *     costo_total: float,
     *     desglose: array<int, array{concepto: string, monto: float}>,
     *     margen_aplicado: float,
     *     precio_minimo_permitido: float|null,
     *     alerta_piso: bool
     * }
     */
    public function calcular(
        float $costoBase,
        array $cargos,
        string $margenTipo,
        float $margenValor,
        ?float $descuentoMaximoPct,
        ?float $margenMinimoPct
    ): array {
        $sumaCargos = array_sum(array_column($cargos, 'monto'));

        $ventaBase = $margenTipo === 'fijo'
            ? $costoBase + $margenValor
            : $costoBase * (1 + $margenValor / 100);

        $margenAplicado = $ventaBase - $costoBase;
        $costoTotal = $costoBase + $sumaCargos;
        $ventaTotal = $ventaBase + $sumaCargos;

        $precioMinimoPermitido = $this->calcularPrecioMinimoPermitido($costoBase, $ventaBase, $descuentoMaximoPct, $margenMinimoPct);

        $desglose = [['concepto' => 'Costo base', 'monto' => round($costoBase, 2)]];
        foreach ($cargos as $cargo) {
            $desglose[] = ['concepto' => $cargo['nombre'], 'monto' => round((float) $cargo['monto'], 2)];
        }
        $desglose[] = ['concepto' => 'Margen', 'monto' => round($margenAplicado, 2)];

        return [
            'venta_base' => round($ventaBase, 2),
            'venta_total' => round($ventaTotal, 2),
            'costo_total' => round($costoTotal, 2),
            'desglose' => $desglose,
            'margen_aplicado' => round($margenAplicado, 2),
            'precio_minimo_permitido' => $precioMinimoPermitido !== null ? round($precioMinimoPermitido, 2) : null,
            'alerta_piso' => $precioMinimoPermitido !== null && $this->cruzaPiso($ventaBase, $precioMinimoPermitido),
        ];
    }

    /**
     * Evalúa el piso contra un precio ya editado (descuento_pct o
     * precio_convertido) — usado por AlternativaItemController::update()
     * para la validación en vivo, sin recalcular todo el margen de nuevo.
     */
    public function evaluarPiso(float $precioEditado, float $costoBase, float $ventaBase, ?float $descuentoMaximoPct, ?float $margenMinimoPct): array
    {
        $precioMinimoPermitido = $this->calcularPrecioMinimoPermitido($costoBase, $ventaBase, $descuentoMaximoPct, $margenMinimoPct);

        return [
            'precio_minimo_permitido' => $precioMinimoPermitido !== null ? round($precioMinimoPermitido, 2) : null,
            'alerta_piso' => $precioMinimoPermitido !== null && $this->cruzaPiso($precioEditado, $precioMinimoPermitido),
        ];
    }

    // precio_minimo_permitido = el MAYOR entre el piso por margen_minimo_pct
    // (sobre costo_base) y el piso por descuento_maximo_pct (sobre venta_base)
    // — plan-modulo-cotizaciones-reservas.md §3.1. null cuando ninguno de los
    // 2 pisos está configurado (tarifa sin piso protegido).
    private function calcularPrecioMinimoPermitido(float $costoBase, float $ventaBase, ?float $descuentoMaximoPct, ?float $margenMinimoPct): ?float
    {
        $pisos = [];

        if ($margenMinimoPct !== null) {
            $pisos[] = $costoBase * (1 + $margenMinimoPct / 100);
        }

        if ($descuentoMaximoPct !== null) {
            $pisos[] = $ventaBase * (1 - $descuentoMaximoPct / 100);
        }

        return empty($pisos) ? null : max($pisos);
    }

    // Tolerancia de centavo para evitar falsos positivos por redondeo decimal.
    private function cruzaPiso(float $precio, float $precioMinimoPermitido): bool
    {
        return $precio < ($precioMinimoPermitido - 0.005);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sesión 11b4 — precio de paquete_combo (plan-modulo-cotizaciones-reservas.md
    // §3.7, punto 3 del diseño). Dirección inversa a calcular(): acá se parte
    // de una venta BRUTA ya conocida (suma de los tours incluidos) y se le
    // resta un descuento, en vez de partir de un costo y sumarle margen.
    // Resolución de "cuánto cuesta/vende cada tour_simple incluido" vive en
    // ComboExplosionService (sí toca Eloquent) — este método sigue siendo
    // matemática pura, sin tocar BD, mismo criterio que el resto de la clase.
    // ═══════════════════════════════════════════════════════════════

    /**
     * @param  array<int, array{costo_total: float, venta_total: float}>  $tourTotales
     * @return array{
     *     costo_total_combo: float,
     *     venta_bruta_combo: float,
     *     venta_neta_combo: float,
     *     descuento_aplicado: float,
     *     margen_resultante_pct: float|null
     * }
     */
    public function calcularCombo(array $tourTotales, ?string $descuentoTipo, ?float $descuentoValor): array
    {
        $costoTotalCombo = array_sum(array_column($tourTotales, 'costo_total'));
        $ventaBrutaCombo = array_sum(array_column($tourTotales, 'venta_total'));

        $ventaNetaCombo = $this->aplicarDescuento($ventaBrutaCombo, $descuentoTipo, $descuentoValor);
        $descuentoAplicado = round($ventaBrutaCombo - $ventaNetaCombo, 2);

        $margenResultantePct = $costoTotalCombo > 0
            ? round((($ventaNetaCombo - $costoTotalCombo) / $costoTotalCombo) * 100, 2)
            : null;

        return [
            'costo_total_combo' => round($costoTotalCombo, 2),
            'venta_bruta_combo' => round($ventaBrutaCombo, 2),
            'venta_neta_combo' => round($ventaNetaCombo, 2),
            'descuento_aplicado' => $descuentoAplicado,
            'margen_resultante_pct' => $margenResultantePct,
        ];
    }

    // 'monto' resta un valor fijo; 'porcentaje' resta sobre venta_bruta. Sin
    // tipo/valor configurado, no hay descuento (venta_neta = venta_bruta) —
    // combo sin descuento es un caso válido (el combo puede existir solo para
    // secuenciar tours, sin rebaja de precio).
    public function aplicarDescuento(float $ventaBruta, ?string $descuentoTipo, ?float $descuentoValor): float
    {
        if ($descuentoTipo === null || $descuentoValor === null) {
            return round($ventaBruta, 2);
        }

        $descuento = $descuentoTipo === 'monto' ? $descuentoValor : $ventaBruta * ($descuentoValor / 100);

        return round($ventaBruta - $descuento, 2);
    }

    // tipo_cambio_agencia.valor = cuántos PEN equivalen a 1 USD (cotización
    // estándar en Perú, ej. 3.75) — documentado acá porque el plan no fija
    // la dirección explícita de la fórmula. Antes duplicado en
    // AlternativaItemController — Sesión 11b3 lo movió acá para que
    // AlternativaController (descuento_global_pct) lo reuse sin repetir la
    // fórmula de conversión de moneda por segunda vez.
    public function convertirMoneda(float $monto, string $monedaOrigen, string $monedaDestino, float $tipoCambio): float
    {
        if ($monedaOrigen === $monedaDestino) {
            return round($monto, 2);
        }

        return $monedaOrigen === 'USD' && $monedaDestino === 'PEN'
            ? round($monto * $tipoCambio, 2)
            : round($monto / $tipoCambio, 2);
    }
}
