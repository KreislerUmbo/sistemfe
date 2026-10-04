<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Enums\Creditos\AccionMoraReprogramacion;
use App\Services\Creditos\Motor\Enums\TipoCargoReprogramacion;

/** Vista previa de una reprogramación de fechas (00 1.9). Montos en centavos. */
final readonly class PreviewReprogramacion
{
    /** @param list<CambioFecha> $cambios cuotas cuya fecha cambia */
    public function __construct(
        public array $cambios,
        public int $moraAcumulada,
        public AccionMoraReprogramacion $accionMora,
        public TipoCargoReprogramacion $cargoTipo,
        public int $cargoSugerido,
        public int $cargo,
    ) {
    }
}
