<?php

namespace App\Http\Controllers\Concerns;

use App\Services\AgenciaViajes\CotizacionDuena;

/**
 * T-025 (H2) — cada acción del cotizador pregunta a CotizacionPolicy por la
 * cotización dueña: solo su vendedor o quien tiene cotizaciones.ver_todas.
 * Antes, con el permiso de la ruta bastaba para leer o cambiar la cotización
 * de otro vendedor conociendo un id. 403 si no corresponde.
 */
trait AutorizaCotizacion
{
    protected function autorizarCotizacion(string $habilidad, mixed $recurso): void
    {
        $cotizacion = CotizacionDuena::de($recurso);

        if ($cotizacion) {
            $this->authorize($habilidad, $cotizacion);
        }
    }
}
