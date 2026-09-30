<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Services\Creditos\Motor\Fecha;

/** Una línea del exigible de hoy (cuota vencida, próxima por vencer o solo cargo). Montos en centavos. */
final readonly class DeudaCuota
{
    public function __construct(
        public int $numeroCuota,
        public Fecha $fechaVencimiento,
        /** Lo exigible de la cuota sin la mora (capital + interés si corresponde, más su cargo). */
        public int $pendiente,
        public int $mora,
        public int $diasAtraso,
        public bool $moraTopeAlcanzado,
        public bool $vencida,
    ) {
    }
}
