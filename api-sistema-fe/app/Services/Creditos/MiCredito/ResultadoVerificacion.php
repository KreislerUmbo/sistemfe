<?php

declare(strict_types=1);

namespace App\Services\Creditos\MiCredito;

use Carbon\CarbonInterface;

/** Resultado de comprobar los 4 caracteres del documento en "Mi crédito". */
final class ResultadoVerificacion
{
    private function __construct(
        public readonly string $estado,   // correcto | incorrecto | bloqueado
        public readonly ?string $credencial = null,
        public readonly ?CarbonInterface $hasta = null,   // vence la credencial / termina el bloqueo
        public readonly int $intentosRestantes = 0,
    ) {
    }

    public static function correcto(string $credencial, CarbonInterface $vence): self
    {
        return new self('correcto', $credencial, $vence);
    }

    public static function incorrecto(int $restantes): self
    {
        return new self('incorrecto', intentosRestantes: $restantes);
    }

    public static function bloqueado(?CarbonInterface $hasta): self
    {
        return new self('bloqueado', hasta: $hasta);
    }
}
