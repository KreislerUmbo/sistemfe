<?php

namespace App\Services\AgenciaViajes;

// T-033 — una alternativa que no se puede crear (tope de alternativas, tipo de cambio fuera de
// rango o sin ninguno disponible). El controller la devuelve como 422 con su mensaje y $extra
// (requiere_confirmacion / requiere_tipo_cambio) para que la pantalla sepa qué pedir. Lanzada
// dentro de la transacción de CotizacionController::store(), deshace también la cotización.
class AlternativaCreacionException extends \RuntimeException
{
    public function __construct(string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
