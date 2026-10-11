<?php

declare(strict_types=1);

namespace App\Services\Push;

/** Resultado de un envío push: cuántos dispositivos lo recibieron y cuáles ya no existen. */
final class ResultadoPush
{
    /**
     * @param list<int> $vencidas ids de push_suscripciones que el servicio dio por muertas (404/410)
     * @param list<string> $errores
     */
    public function __construct(
        public readonly int $entregadas,
        public readonly array $vencidas = [],
        public readonly array $errores = [],
    ) {
    }
}
