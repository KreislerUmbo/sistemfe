<?php

declare(strict_types=1);

namespace App\Services\Creditos\MiCredito;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** El cliente no tiene documento registrado: no se le puede crear "Mi crédito" (422). */
final class SinDocumentoException extends HttpException
{
    public function __construct(string $mensaje)
    {
        parent::__construct(422, $mensaje);
    }
}
