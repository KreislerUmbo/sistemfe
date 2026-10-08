<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Services\Creditos\Motor\Dto\Cronograma;
use App\Services\Creditos\Motor\Dto\Liquidacion;
use App\Services\Creditos\Motor\Dto\ResultadoLimites;

/**
 * Vista previa de una renovación (00 1.21). Montos en centavos. entregaNeta = capital nuevo − lo que
 * debe hoy: > 0 se le entrega la diferencia, 0 no hay movimiento de dinero, < 0 el cliente paga la
 * diferencia (ej.: paga el interés y renueva por el mismo capital).
 */
final readonly class PreviewRenovacion
{
    public const ENTREGA = 'entrega';
    public const SIN_MOVIMIENTO = 'sin_movimiento';
    public const COBRO = 'cobro';

    public function __construct(
        public Liquidacion $liquidacion,
        public int $capitalNuevo,
        public int $entregaNeta,
        public Cronograma $cronograma,
        public ResultadoLimites $limites,
    ) {
    }

    public static function movimiento(int $entregaNeta): string
    {
        return $entregaNeta > 0 ? self::ENTREGA : ($entregaNeta < 0 ? self::COBRO : self::SIN_MOVIMIENTO);
    }
}
