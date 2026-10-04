<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor;

use App\Services\Creditos\Motor\Enums\ModoRedondeo;

/**
 * Aritmética de dinero del motor, en centavos enteros y sin floats.
 * `dividir()` es la ÚNICA función de redondeo del módulo (plan §0): todo cálculo arma su
 * fracción exacta de enteros y redondea una sola vez al final.
 */
final class Redondeo
{
    public const PASO_CENTIMO = 1;
    public const PASO_DIEZ_CENTIMOS = 10;

    /** Redondea numerador/denominador al múltiplo de $paso más cercano (medio hacia arriba) o hacia abajo. */
    public static function dividir(
        int $numerador,
        int $denominador,
        int $paso = self::PASO_CENTIMO,
        ModoRedondeo $modo = ModoRedondeo::MedioArriba,
    ): int {
        if ($denominador <= 0 || $paso <= 0) {
            throw new \InvalidArgumentException('Denominador y paso deben ser positivos.');
        }

        $divisor = self::multiplicar($denominador, $paso);
        $signo = $numerador < 0 ? -1 : 1;
        $absoluto = abs($numerador);
        $cociente = intdiv($absoluto, $divisor);
        $resto = $absoluto % $divisor;

        if ($modo === ModoRedondeo::MedioArriba && 2 * $resto >= $divisor) {
            $cociente++;
        }

        return $signo * $cociente * $paso;
    }

    /** Producto de enteros que falla explícitamente si desborda (PHP pasaría a float en silencio). */
    public static function multiplicar(int ...$factores): int
    {
        $producto = 1;
        foreach ($factores as $factor) {
            $resultado = $producto * $factor;
            if (! is_int($resultado)) {
                throw new \OverflowException('Desborde de enteros en un cálculo de dinero.');
            }
            $producto = $resultado;
        }

        return $producto;
    }
}
