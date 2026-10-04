<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Models\Creditos\Credito;

/** Lo cobrado hoy a un crédito (uno o varios pagos válidos). Monto en centavos. */
final readonly class CobroDelDia
{
    /** @param list<string> $metodos nombres de los métodos de pago usados */
    public function __construct(
        public Credito $credito,
        public int $montoAplicado,
        public \DateTimeInterface $ultimoPago,
        public array $metodos,
    ) {
    }
}
