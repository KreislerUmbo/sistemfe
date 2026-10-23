<?php

namespace App\Models\AgenciaViajes\Concerns;

// Regla del tratamiento tributario en Agencia de Viajes (fila 13, 22-oct-2026,
// decidida con el usuario): un servicio turístico solo queda exonerado porque
// se presta en la Amazonía (Ley 27037). Por eso, en los modelos de Agencia,
// código SUNAT 20 (exonerado) ⇒ destino_tributario 'amazonia'. La combinación
// "20 + nacional" era un dato mal cargado (el formulario de la tarifa dejaba
// elegir las dos listas por separado).
//
// Solo para modelos de Agencia: en retail (products, sales, sale_details)
// "exonerado + nacional" sí es válido (p. ej. alimentos del Apéndice I del
// IGV) y esta regla NO se aplica. La pantalla aplica la misma regla
// (admin-start-kit/src/helpers/tratamientoTributarioAgencia.ts).
//
// Laravel ejecuta solo bootExoneradoEsAmazonia() en cada modelo que use el
// trait (boot + nombre del trait). Un modelo con otros nombres de columna los
// declara en $columnasTratamiento = ['tip_afe_igv' => …, 'destino' => …].
trait ExoneradoEsAmazonia
{
    public static function bootExoneradoEsAmazonia(): void
    {
        static::saving(function ($modelo) {
            $columnaIgv = $modelo->columnasTratamiento['tip_afe_igv'] ?? 'tip_afe_igv';
            $columnaDestino = $modelo->columnasTratamiento['destino'] ?? 'destino_tributario';

            if ((string) $modelo->{$columnaIgv} === '20') {
                $modelo->{$columnaDestino} = 'amazonia';
            }
        });
    }
}
