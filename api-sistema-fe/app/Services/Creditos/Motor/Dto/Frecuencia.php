<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;

/** Frecuencia de pago (plan 1.3). Quincenal "corrido" = unidad dia, intervalo 15. */
final readonly class Frecuencia
{
    /** Valor de $diasQuincena que representa el último día del mes. */
    public const ULTIMO_DIA = 'ultimo';

    /**
     * @param list<int|'ultimo'>|null $diasQuincena solo para unidad=quincena, p.ej. [15, 'ultimo']
     */
    public function __construct(
        public FrecuenciaUnidad $unidad,
        public int $intervalo = 1,
        public ?array $diasQuincena = null,
    ) {
    }
}
