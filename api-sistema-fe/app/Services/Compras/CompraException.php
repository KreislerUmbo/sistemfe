<?php

namespace App\Services\Compras;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Rechazo de una regla de Compras, con su código HTTP y lo que la pantalla necesita. */
class CompraException extends RuntimeException
{
    public function __construct(string $mensaje, public readonly int $status = 422, public readonly array $extra = [])
    {
        parent::__construct($mensaje);
    }

    // Es un rechazo esperado, como una validación: no va al log.
    public function report(): void {}

    public function render(): JsonResponse
    {
        return response()->json(['code' => $this->status, 'message' => $this->getMessage()] + $this->extra, $this->status);
    }
}
