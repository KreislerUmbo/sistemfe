<?php

declare(strict_types=1);

namespace App\Services\Creditos;

/**
 * Frontera soles ↔ centavos (00 §0): la BD guarda numeric(12,2) y el motor trabaja en
 * centavos enteros. La conversión parsea el texto; nunca multiplica un float.
 */
final class Dinero
{
    private const CENTAVOS_POR_SOL = 100;

    public static function aCentavos(string|int|float $soles): int
    {
        if (is_int($soles)) {
            return $soles * self::CENTAVOS_POR_SOL;
        }
        $texto = is_float($soles) ? sprintf('%.2f', $soles) : trim($soles);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $texto, $m)) {
            throw new \InvalidArgumentException("Monto inválido: {$texto}.");
        }
        $centavos = (int) $m[2] * self::CENTAVOS_POR_SOL + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$centavos : $centavos;
    }

    public static function aSoles(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $absoluto = abs($centavos);

        return sprintf('%s%d.%02d', $signo, intdiv($absoluto, self::CENTAVOS_POR_SOL), $absoluto % self::CENTAVOS_POR_SOL);
    }
}
