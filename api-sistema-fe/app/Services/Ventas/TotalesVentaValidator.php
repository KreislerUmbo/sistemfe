<?php

namespace App\Services\Ventas;

use App\Models\Product\DetractionCode;
use App\Models\Product\TaxConfig;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Symfony\Component\HttpKernel\Exception\HttpException;

// T-114 — Los totales de la cabecera de una venta tienen que salir de sus líneas.
//
// SaleController::store()/update() guardan la cabecera tal como llega del front. T-109 revisa
// cada línea (solo sin el permiso de precio), pero con un request armado las líneas pueden cuadrar
// y la cabecera decir menos, y la caja, el PDF y el XML usan la cabecera. Este validador recalcula
// la cabecera desde las líneas del payload y rechaza con 422 si algo no coincide. Corre con y sin
// el permiso `cambiar_precio_venta`: el permiso deja cambiar precios, no mandar totales que no
// salen de las líneas.
//
// La cuenta es la de sumDetails() de register.vue (edit.vue tiene otra copia con el mismo
// resultado: se comparó el 20-nov, ver la tarea), más lo que store() arma en `data`:
//   - gratuitas (tip_afe_igv 11) quedan fuera de la base de cobro (subtotal, IGV, ISC, ICBPER);
//   - el descuento global se prorratea por línea según su peso en el subtotal bruto, y el IGV y
//     el ISC porcentual salen de esa base neta; ICBPER y el ISC específico no se prorratean;
//   - mto_oper_gravadas/exoneradas/inafectas/exportacion = base neta del descuento por afectación
//     (10/20/30/40); gratuitas = subtotal bruto de las 11; ivap_total = IGV de las 17 sin prorrateo;
//   - base del régimen = (subtotal − descuento global) + IGV + ICBPER + ISC;
//     total = base + percepción − retención − detracción;
//   - mto_imp_venta = base redondeada a 0,1 y redondeo = la diferencia.
// Lo que NO valida (otras tareas): estado de pago, deuda, pagos, cronograma, ni los importes de
// cada línea (eso es PrecioVentaValidator, y solo sin el permiso de precio).
//
// Regímenes: la tasa y el mínimo salen de tax_configs (retención, percepción) y de detraction_codes
// (detracción), con los mismos valores por defecto que usa el front (3 %, 2 %, S/ 700) cuando no hay
// configuración. La tasa enviada tiene que ser una vigente: la pantalla (register.vue y edit.vue) la
// recalcula con la vigente en cada guardado, así que editar una venta vieja también la manda vigente.
//
// Todo en BigDecimal. Tolerancia de céntimos porque el front suma en coma flotante y redondea a 4
// decimales; mto_imp_venta se compara contra la base con la holgura del redondeo a 0,1.
class TotalesVentaValidator
{
    private const TOLERANCIA = '0.01';
    private const TOLERANCIA_TOTAL = '0.02';
    private const MINIMO_REGIMEN = '700';

    /** Afectaciones que la pantalla puede generar (updateItem de register.vue). */
    private const AFECTACIONES = ['10', '11', '17', '20', '30', '40'];

    /**
     * @param array<string, mixed> $cabecera   los totales del payload (subtotal, igv, total, …)
     * @param array<int, array>    $detalles   sale_details del payload
     */
    public function validar(array $cabecera, array $detalles): void
    {
        if ($detalles === []) {
            throw $this->rechazo('la venta no tiene líneas.');
        }

        $esperado = $this->calcular($cabecera, $detalles);
        $regimen = $this->regimen($cabecera, $esperado['base']);

        $esperado['total'] = $esperado['base']
            ->plus($regimen['percepcion'])->minus($regimen['retencion'])->minus($regimen['detraccion']);

        $revisar = [
            'subtotal' => [$esperado['subtotal'], $cabecera['subtotal'] ?? null, self::TOLERANCIA],
            'discount' => [$esperado['descuento_lineas'], $cabecera['discount'] ?? null, self::TOLERANCIA],
            'igv' => [$esperado['igv'], $cabecera['igv'] ?? null, self::TOLERANCIA],
            'isc_total' => [$esperado['isc'], $cabecera['isc_total'] ?? null, self::TOLERANCIA],
            'icbper_total' => [$esperado['icbper'], $cabecera['icbper_total'] ?? null, self::TOLERANCIA],
            'ivap_total' => [$esperado['ivap'], $cabecera['ivap_total'] ?? null, self::TOLERANCIA],
            'total_impuestos' => [$esperado['total_impuestos'], $cabecera['total_impuestos'] ?? null, self::TOLERANCIA],
            'valor_venta' => [$esperado['valor_venta'], $cabecera['valor_venta'] ?? null, self::TOLERANCIA],
            'mto_oper_gravadas' => [$esperado['oper']['10'], $cabecera['mto_oper_gravadas'] ?? null, self::TOLERANCIA],
            'mto_oper_exoneradas' => [$esperado['oper']['20'], $cabecera['mto_oper_exoneradas'] ?? null, self::TOLERANCIA],
            'mto_oper_inafectas' => [$esperado['oper']['30'], $cabecera['mto_oper_inafectas'] ?? null, self::TOLERANCIA],
            'mto_oper_exportacion' => [$esperado['oper']['40'], $cabecera['mto_oper_exportacion'] ?? null, self::TOLERANCIA],
            'mto_oper_gratuitas' => [$esperado['gratuitas'], $cabecera['mto_oper_gratuitas'] ?? null, self::TOLERANCIA],
            'monto_retencion' => [$regimen['retencion'], $cabecera['monto_retencion'] ?? null, self::TOLERANCIA],
            'monto_detraccion' => [$regimen['detraccion'], $cabecera['monto_detraccion'] ?? null, self::TOLERANCIA],
            'monto_percepcion' => [$regimen['percepcion'], $cabecera['monto_percepcion'] ?? null, self::TOLERANCIA],
            'total' => [$esperado['total'], $cabecera['total'] ?? null, self::TOLERANCIA_TOTAL],
        ];
        foreach ($revisar as $campo => [$calculado, $enviado, $tolerancia]) {
            if (!$this->coincide($calculado, $this->numero($enviado, $campo), $tolerancia)) {
                throw $this->rechazo("$campo no coincide con la suma de las líneas.");
            }
        }

        // mto_imp_venta = base a 0,1: se acepta cualquiera de los dos lados del redondeo (el front
        // y este cálculo pueden desempatar distinto un …,x5 exacto) y redondeo tiene que ser la
        // diferencia entre ambos.
        $imp = $this->numero($cabecera['mto_imp_venta'] ?? null, 'mto_imp_venta');
        if (!$this->coincide($esperado['base'], $imp, '0.06')) {
            throw $this->rechazo('mto_imp_venta no coincide con la suma de las líneas.');
        }
        $redondeo = $this->numero($cabecera['redondeo'] ?? null, 'redondeo');
        if (!$this->coincide($imp->minus($esperado['base']), $redondeo, '0.015')) {
            throw $this->rechazo('redondeo no coincide con la diferencia entre mto_imp_venta y el total.');
        }
    }

    /** @return array<string, mixed> */
    private function calcular(array $cabecera, array $detalles): array
    {
        $descuentoGlobal = $this->numero($cabecera['discount_global'] ?? null, 'discount_global');

        $lineas = [];
        foreach (array_values($detalles) as $i => $d) {
            $n = $i + 1;
            $afectacion = (string) ($d['tip_afe_igv'] ?? '');
            if (!in_array($afectacion, self::AFECTACIONES, true)) {
                throw $this->rechazo("la línea $n tiene una afectación de IGV que no se puede validar ($afectacion).");
            }
            $producto = (array) ($d['product'] ?? []);
            $lineas[] = [
                'afectacion' => $afectacion,
                'subtotal' => $this->numero($d['subtotal'] ?? null, "la línea $n: subtotal"),
                'discount' => $this->numero($d['discount'] ?? null, "la línea $n: discount"),
                'igv' => $this->numero($d['igv'] ?? null, "la línea $n: igv"),
                'isc' => $this->numero($d['isc'] ?? null, "la línea $n: isc"),
                'icbper' => $this->numero($d['icbper'] ?? null, "la línea $n: icbper"),
                'porcentaje_igv' => $this->numero($d['porcentaje_igv'] ?? null, "la línea $n: porcentaje_igv"),
                'total_impuestos' => $this->numero($d['total_impuestos'] ?? null, "la línea $n: total_impuestos"),
                'mto_valor_venta' => $this->numero($d['mto_valor_venta'] ?? null, "la línea $n: mto_valor_venta"),
                'tipo_isc' => $d['tipo_isc'] ?? null,
                'es_isc' => (bool) ($producto['is_isc'] ?? false),
                'porcentaje_isc' => $this->numero($producto['percentage_isc'] ?? null, "la línea $n: percentage_isc"),
            ];
        }

        $cobro = array_filter($lineas, fn ($l) => $l['afectacion'] !== '11');
        $bruto = $this->sumar($cobro, fn ($l) => $l['subtotal']);

        // Subtotal de línea neto del descuento global, prorrateado por su peso en el bruto.
        $neto = fn (array $l): BigDecimal => $bruto->isPositive()
            ? $l['subtotal']->minus($descuentoGlobal->multipliedBy($l['subtotal'])->dividedBy($bruto, 10, RoundingMode::HALF_UP))
            : $l['subtotal'];
        $descuentoDe = fn (array $l): BigDecimal => $bruto->isPositive()
            ? $descuentoGlobal->multipliedBy($l['subtotal'])->dividedBy($bruto, 10, RoundingMode::HALF_UP)
            : BigDecimal::zero();

        $igv = $this->sumar($cobro, fn ($l) => $neto($l)->multipliedBy($l['porcentaje_igv'])->dividedBy(100, 10, RoundingMode::HALF_UP));
        $icbper = $this->sumar($cobro, fn ($l) => $l['icbper']);
        // ISC: solo el porcentual ('01', '03') con producto afecto se prorratea; el específico ('02')
        // y el de un producto no afecto va tal cual.
        $isc = $this->sumar($cobro, function ($l) use ($descuentoDe) {
            if (!$l['es_isc'] || $l['tipo_isc'] === '02') {
                return $l['isc'];
            }
            $base = $l['tipo_isc'] === '03' ? $l['mto_valor_venta'] : $l['subtotal'];
            return $base->minus($descuentoDe($l))->multipliedBy($l['porcentaje_isc'])->dividedBy(100, 10, RoundingMode::HALF_UP);
        });

        $subtotal = $this->sumar($cobro, fn ($l) => $l['subtotal']);
        $valorVenta = $subtotal->minus($descuentoGlobal);

        $oper = [];
        foreach (['10', '20', '30', '40'] as $codigo) {
            $oper[$codigo] = $this->sumar(
                array_filter($lineas, fn ($l) => $l['afectacion'] === $codigo),
                fn ($l) => $neto($l)
            );
        }

        return [
            'subtotal' => $subtotal,
            'descuento_lineas' => $this->sumar($cobro, fn ($l) => $l['discount']),
            'igv' => $igv,
            'isc' => $isc,
            'icbper' => $icbper,
            'ivap' => $this->sumar(array_filter($lineas, fn ($l) => $l['afectacion'] === '17'), fn ($l) => $l['igv']),
            'total_impuestos' => $this->sumar($lineas, fn ($l) => $l['total_impuestos']),
            'gratuitas' => $this->sumar(array_filter($lineas, fn ($l) => $l['afectacion'] === '11'), fn ($l) => $l['subtotal']),
            'valor_venta' => $valorVenta,
            'oper' => $oper,
            'base' => $valorVenta->plus($igv)->plus($icbper)->plus($isc),
        ];
    }

    /**
     * Montos de retención, detracción y percepción que le tocan a la venta (cero los que no son el
     * régimen elegido o si la base no pasa el mínimo).
     *
     * @return array{retencion: BigDecimal, detraccion: BigDecimal, percepcion: BigDecimal}
     */
    private function regimen(array $cabecera, BigDecimal $base): array
    {
        $monto = ['retencion' => BigDecimal::zero(), 'detraccion' => BigDecimal::zero(), 'percepcion' => BigDecimal::zero()];

        switch ((int) ($cabecera['retencion_igv'] ?? 0)) {
            case 1:
                [$tasa, $minimo] = $this->tasaConfigurada('RETENCION', '3', $cabecera['porcentaje_retencion'] ?? null, 'la retención');
                $monto['retencion'] = $this->aplicarRegimen($base, $tasa, $minimo);
                break;
            case 2:
                $codigo = $cabecera['codigo_detraccion'] ?? null;
                $detraccion = $codigo ? DetractionCode::where('codigo', $codigo)->where('estado', true)->first() : null;
                if ($detraccion) {
                    $tasa = BigDecimal::of((string) $detraccion->tasa_porcentaje)->dividedBy(100, 10, RoundingMode::HALF_UP);
                    $this->exigirTasa($tasa, $cabecera['porcentaje_detraccion'] ?? null, 'la detracción');
                    if ($tasa->isPositive()) {
                        $minimo = BigDecimal::of((string) ($detraccion->monto_minimo ?? self::MINIMO_REGIMEN));
                        $monto['detraccion'] = $this->aplicarRegimen($base, $tasa, $minimo);
                    }
                }
                break;
            case 3:
                [$tasa, $minimo] = $this->tasaConfigurada('PERCEPCION', '2', $cabecera['porcentaje_percepcion'] ?? null, 'la percepción');
                $monto['percepcion'] = $this->aplicarRegimen($base, $tasa, $minimo);
                break;
        }

        return $monto;
    }

    /**
     * Tasa (como fracción) y mínimo de retención o percepción. Vale cualquiera de las tasas
     * activas en tax_configs (el front usa la primera); sin configuración, el valor por defecto
     * del front.
     *
     * @return array{0: BigDecimal, 1: BigDecimal}
     */
    private function tasaConfigurada(string $tipo, string $porDefecto, mixed $enviada, string $que): array
    {
        $filas = TaxConfig::where('tipo', $tipo)->where('es_activo', true)->orderBy('id')->get(['tasa_porcentaje']);
        $tasas = $filas->map(fn ($f) => BigDecimal::of((string) $f->tasa_porcentaje))->all() ?: [BigDecimal::of($porDefecto)];

        $enviada = $this->numero($enviada, "porcentaje de $que");
        foreach ($tasas as $porcentaje) {
            $tasa = $porcentaje->dividedBy(100, 10, RoundingMode::HALF_UP);
            if ($this->coincide($tasa, $enviada, '0.00001')) {
                // tax_configs no guarda mínimo: el front usa S/ 700 (monto_minimo no existe en la tabla).
                return [$enviada, BigDecimal::of(self::MINIMO_REGIMEN)];
            }
        }

        throw $this->rechazo("el porcentaje de $que no es el vigente.");
    }

    private function exigirTasa(BigDecimal $vigente, mixed $enviada, string $que): void
    {
        $enviada = $this->numero($enviada, "porcentaje de $que");
        if (!$this->coincide($vigente, $enviada, '0.00001')) {
            throw $this->rechazo("el porcentaje de $que no es el del código elegido.");
        }
    }

    private function aplicarRegimen(BigDecimal $base, BigDecimal $tasa, BigDecimal $minimo): BigDecimal
    {
        return $base->isGreaterThan($minimo)
            ? $base->multipliedBy($tasa)->toScale(2, RoundingMode::HALF_UP)
            : BigDecimal::zero();
    }

    /** @param iterable<array> $lineas */
    private function sumar(iterable $lineas, callable $valor): BigDecimal
    {
        $suma = BigDecimal::zero();
        foreach ($lineas as $l) {
            $suma = $suma->plus($valor($l));
        }

        return $suma;
    }

    private function coincide(BigDecimal $a, ?BigDecimal $b, string $tolerancia): bool
    {
        return $b !== null && $a->minus($b)->abs()->isLessThanOrEqualTo($tolerancia);
    }

    /** Número del payload; ausente = 0 (es lo que store()/update() guardan); no numérico = 422. */
    private function numero(mixed $valor, string $campo): BigDecimal
    {
        if ($valor === null || $valor === '') {
            return BigDecimal::zero();
        }
        if (!is_numeric($valor)) {
            throw $this->rechazo("$campo no es un número.");
        }

        return BigDecimal::of(is_float($valor) ? sprintf('%.10F', $valor) : (string) $valor);
    }

    private function rechazo(string $motivo): HttpException
    {
        // Mismo estilo que el resto de validadores de SaleController: 422 con `message`.
        return new HttpException(422, "Los totales de la venta no coinciden con sus líneas: $motivo");
    }
}
