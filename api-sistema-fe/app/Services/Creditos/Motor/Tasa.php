<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor;

/**
 * Porcentaje expresado en diezmilésimas de punto (numeric(8,4) de Postgres sin pasar por float):
 * '20.0000' → 200000. Como fracción: diezmilesimas / DENOMINADOR.
 */
final readonly class Tasa
{
    public const DENOMINADOR = 1_000_000;

    public function __construct(public int $diezmilesimas)
    {
        if ($diezmilesimas < 0) {
            throw new \InvalidArgumentException('La tasa no puede ser negativa.');
        }
    }

    /** @param string $porcentaje p.ej. '20', '20.5', '3.3333' */
    public static function desdeTexto(string $porcentaje): self
    {
        if (! preg_match('/^(\d{1,4})(?:\.(\d{1,4}))?$/', trim($porcentaje), $m)) {
            throw new \InvalidArgumentException("Tasa inválida: {$porcentaje}.");
        }

        $decimales = str_pad($m[2] ?? '', 4, '0');

        return new self((int) $m[1] * 10_000 + (int) $decimales);
    }

    public function aTexto(): string
    {
        return sprintf('%d.%04d', intdiv($this->diezmilesimas, 10_000), $this->diezmilesimas % 10_000);
    }
}
