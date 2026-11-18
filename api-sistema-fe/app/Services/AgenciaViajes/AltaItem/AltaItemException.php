<?php

namespace App\Services\AgenciaViajes\AltaItem;

// T-095 — un alta de ítem del cotizador que no procede (validación o regla de negocio). El
// controller la devuelve como 422 con el mismo mensaje.
class AltaItemException extends \RuntimeException
{
}
