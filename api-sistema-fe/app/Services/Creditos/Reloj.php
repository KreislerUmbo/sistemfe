<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Services\Creditos\Motor\Fecha;
use Carbon\CarbonImmutable;

/**
 * "Hoy" del módulo en America/Lima (00 §0): la app corre en UTC y de noche la fecha UTC
 * ya es la del día siguiente. Los tests registran una instancia con la hora fija.
 */
class Reloj
{
    public const ZONA = 'America/Lima';

    public function __construct(private readonly ?CarbonImmutable $fijo = null)
    {
    }

    public function ahora(): CarbonImmutable
    {
        return $this->fijo?->setTimezone(self::ZONA) ?? CarbonImmutable::now(self::ZONA);
    }

    public function hoy(): Fecha
    {
        return Fecha::desdeTexto($this->ahora()->format('Y-m-d'));
    }
}
