<?php

namespace App\Services\AgenciaViajes;

// Un tour que no se puede cargar como línea (solo itinerario, sin precio, IGV
// mezclado sin costo…). El controller lo devuelve como 422 con este mensaje.
class TourLineaException extends \RuntimeException
{
}
