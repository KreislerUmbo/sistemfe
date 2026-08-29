<?php

namespace App\Services;

// 29-ago-2026 — hallazgo del usuario: nombres/títulos de destinos,
// servicios, proveedores (nombre_comercial), guías, tours/paquetes,
// alternativas y aerolíneas se escriben sin ningún criterio (mayúsculas,
// minúsculas, mezclado) porque son campos de texto libre sin
// normalización. Se descartó forzar TODO MAYÚSCULAS (peor legibilidad en
// textos largos, ya reservado en este proyecto para badges/códigos/
// encabezados de tabla) — se usa Capitalización tipo título en español.
//
// Alcance decidido con el usuario: SOLO estos 7 campos, SOLO al escribir
// (store()/update() de cada controller) — nunca reescribe lo ya guardado
// en la base, y nunca toca razon_social (dato fiscal) ni ningún campo de
// clientes.
class TextoFormatoService
{
    // A diferencia del inglés, en español NO se capitaliza cada palabra —
    // artículos/preposiciones/conjunciones cortas quedan en minúscula
    // salvo que sean la primera palabra del texto (ej. "Traslado de la
    // Selva" queda "Traslado de la Selva", nunca "Traslado De La Selva";
    // "La Casa Grande" sí lleva "La" mayúscula por ser la primera
    // palabra). Límite conocido y aceptado: si el conector es parte
    // inherente de un nombre propio ("El Rincón" como nombre de un
    // restaurante) y no va primero en el texto, igual queda en
    // minúscula — no hay forma de distinguir eso de un conector
    // gramatical común sin un diccionario de nombres propios, fuera de
    // alcance acá.
    private const CONECTORES = [
        'de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'o', 'u',
        'en', 'a', 'al', 'con', 'para', 'por', 'un', 'una',
    ];

    // Trade-off aceptado a propósito: al bajar toda la palabra a
    // minúscula antes de recapitalizar la primera letra, un acrónimo
    // interno ya escrito ("TDK Tours") pierde sus mayúsculas propias
    // ("Tdk Tours") — es el mismo comportamiento que cualquier
    // "Capitalizar cada palabra" de un procesador de texto. Necesario
    // para que el caso real que motivó esto ("HOTEL RIOJA" todo en
    // mayúscula) sí quede corregido — sin bajar el resto de la palabra,
    // "HOTEL RIOJA" habría quedado intacto.
    public static function capitalizarNombrePropio(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        $texto = trim($texto);
        if ($texto === '') {
            return $texto;
        }

        // Colapsa espacios múltiples de paso — un typo común al tipear.
        $texto = preg_replace('/\s+/u', ' ', $texto);

        $palabras = explode(' ', $texto);
        $resultado = [];

        foreach ($palabras as $indice => $palabra) {
            if ($palabra === '') {
                continue;
            }

            $palabraMinuscula = mb_strtolower($palabra, 'UTF-8');
            $esConector = in_array($palabraMinuscula, self::CONECTORES, true);

            if ($esConector && $indice !== 0) {
                $resultado[] = $palabraMinuscula;
                continue;
            }

            $primeraLetra = mb_strtoupper(mb_substr($palabraMinuscula, 0, 1, 'UTF-8'), 'UTF-8');
            $resto = mb_substr($palabraMinuscula, 1, null, 'UTF-8');
            $resultado[] = $primeraLetra . $resto;
        }

        return implode(' ', $resultado);
    }
}
