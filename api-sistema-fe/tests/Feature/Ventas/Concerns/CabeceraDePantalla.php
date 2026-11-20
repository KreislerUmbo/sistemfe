<?php

declare(strict_types=1);

namespace Tests\Feature\Ventas\Concerns;

/**
 * T-114 — La cabecera de una venta tal como la arma la pantalla (sumDetails() + store() de
 * register.vue), en floats y por separado de TotalesVentaValidator: así un test que pasa prueba que
 * las dos cuentas dan lo mismo, y no que el validador se compara contra sí mismo.
 *
 * Toma lo que el payload ya trae (líneas, `discount_global`, `retencion_igv`, los `porcentaje_*` y
 * `monto_minimo_detraccion` opcional) y devuelve el payload con la cabecera recalculada.
 */
trait CabeceraDePantalla
{
    /** @param array<string, mixed> $payload */
    private function conCabeceraDeLasLineas(array $payload, float $minimoDetraccion = 700.0): array
    {
        $lineas = $payload['sale_details'];
        $descuentoGlobal = (float) ($payload['discount_global'] ?? 0);

        $cobro = array_filter($lineas, fn ($d) => $d['tip_afe_igv'] !== '11');
        $sumar = fn (array $ls, string $campo) => array_sum(array_map(fn ($d) => (float) ($d[$campo] ?? 0), $ls));
        $bruto = $sumar($cobro, 'subtotal');
        $neto = fn (array $d) => $d['subtotal'] - $descuentoGlobal * ($bruto > 0 ? $d['subtotal'] / $bruto : 0);

        $igv = round(array_sum(array_map(fn ($d) => $neto($d) * ($d['porcentaje_igv'] / 100), $cobro)), 4);
        $icbper = round($sumar($cobro, 'icbper'), 4);
        $isc = round(array_sum(array_map(function ($d) use ($bruto, $descuentoGlobal) {
            $producto = $d['product'] ?? [];
            if (empty($producto['is_isc']) || ($d['tipo_isc'] ?? null) === '02') {
                return $d['isc'] ?? 0;
            }
            $descuentoLinea = $descuentoGlobal * ($bruto > 0 ? $d['subtotal'] / $bruto : 0);
            $pct = $producto['percentage_isc'] ?? 0;
            return ($d['tipo_isc'] ?? null) === '03'
                ? ($d['mto_valor_venta'] - $descuentoLinea) * ($pct / 100)
                : ($d['subtotal'] - $descuentoLinea) * ($pct / 100);
        }, $cobro)), 4);

        $subtotal = round($sumar($cobro, 'subtotal'), 4);
        $base = ($subtotal - $descuentoGlobal) + $igv + $icbper + $isc;

        $montos = ['retencion' => 0.0, 'detraccion' => 0.0, 'percepcion' => 0.0];
        $cond = (int) ($payload['retencion_igv'] ?? 0);
        if ($cond === 1 && $base > 700) {
            $montos['retencion'] = round($base * (float) $payload['porcentaje_retencion'], 2);
        } elseif ($cond === 2 && (float) ($payload['porcentaje_detraccion'] ?? 0) > 0 && $base > $minimoDetraccion) {
            $montos['detraccion'] = round($base * (float) $payload['porcentaje_detraccion'], 2);
        } elseif ($cond === 3 && $base > 700) {
            $montos['percepcion'] = round($base * (float) $payload['porcentaje_percepcion'], 2);
        }

        $porAfectacion = fn (string $codigo) => array_sum(array_map(
            $neto,
            array_filter($lineas, fn ($d) => $d['tip_afe_igv'] === $codigo)
        ));
        $importe = round($base * 10) / 10;

        return array_replace($payload, [
            'subtotal' => $subtotal,
            'igv' => $igv,
            'total' => round($base + $montos['percepcion'] - $montos['retencion'] - $montos['detraccion'], 4),
            'discount' => round($sumar($cobro, 'discount'), 4),
            'mto_oper_gravadas' => $porAfectacion('10'),
            'mto_oper_exoneradas' => $porAfectacion('20'),
            'mto_oper_inafectas' => $porAfectacion('30'),
            'mto_oper_exportacion' => $porAfectacion('40'),
            'mto_oper_gratuitas' => $sumar(array_filter($lineas, fn ($d) => $d['tip_afe_igv'] === '11'), 'subtotal'),
            'isc_total' => $isc,
            'icbper_total' => $icbper,
            'ivap_total' => $sumar(array_filter($lineas, fn ($d) => $d['tip_afe_igv'] === '17'), 'igv'),
            'total_impuestos' => $sumar($lineas, 'total_impuestos'),
            'valor_venta' => $subtotal - $descuentoGlobal,
            'mto_imp_venta' => $importe,
            'redondeo' => round(($importe - $base) * 100) / 100,
            'monto_retencion' => $montos['retencion'],
            'monto_detraccion' => $montos['detraccion'],
            'monto_percepcion' => $montos['percepcion'],
        ]);
    }
}
