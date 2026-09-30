<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Enums\Creditos\AccionMoraReprogramacion;
use App\Services\Creditos\Motor\Fecha;

/** Reprogramación de fechas (00 1.9): desplazar N días desde una cuota o fechas una a una. */
final readonly class SolicitudReprogramacion
{
    /** @param array<int, Fecha> $fechas número de cuota => fecha nueva (modo "editar") */
    public function __construct(
        public ?int $desdeCuota,
        public ?int $dias,
        public array $fechas,
        public AccionMoraReprogramacion $accionMora,
        /** Cargo en centavos elegido por el admin; null = el sugerido por la configuración. */
        public ?int $cargo,
        public string $motivo,
        public string $clave,
    ) {
    }

    public function esDesplazamiento(): bool
    {
        return $this->desdeCuota !== null;
    }
}
