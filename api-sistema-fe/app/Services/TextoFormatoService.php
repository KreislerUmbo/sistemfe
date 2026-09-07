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

    // Bug real (06-sep-2026, reportado por el usuario en el PDF de
    // cotización): texto pegado en el editor rico (Quill) desde Word con
    // viñetas de Wingdings/Symbol sale como "?" en el PDF —
    // "¿QUÉ INCLUYE? ? Transporte turístico. ? Guía turístico...".
    // Confirmado con un dump de bytes reales: el carácter roto es
    // U+F0FC, dentro del rango "Zona de Uso Privado" (U+E000-U+F8FF) de
    // Unicode. No es un bug de fuente/motor de PDF — es un carácter que
    // SOLO tiene significado visual dentro de la fuente Wingtings/Symbol
    // de origen (nunca copiada al pegar); NINGUNA fuente, ni Poppins, va
    // a tener un glifo real para ese código. Se reemplaza por un bullet
    // real (•) porque en la práctica el 100% de los casos reales vistos
    // son viñetas de lista pegadas desde Word, nunca símbolos con otro
    // significado.
    //
    // Se aplica SOLO al renderizar el PDF (acá), nunca reescribe el HTML
    // ya guardado en la base — no hay forma de recuperar qué carácter
    // "quiso" ser el original más allá de asumir que es una viñeta, así
    // que tocar el dato guardado sería una corrección irreversible sobre
    // una suposición.
    public static function sanitizarHtmlParaPdf(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        // Viñetas de Wingdings/Symbol (Zona de Uso Privado) → bullet real.
        $html = preg_replace('/[\x{E000}-\x{F8FF}]/u', '•', $html);

        // Emojis reales tipeados a mano (ej. "🏔️ Ubicación", encontrado
        // 06-sep-2026 en la misma sesión que el bug de Wingdings, caso
        // DISTINTO: acá el carácter SÍ es un emoji real y válido, solo que
        // ninguna fuente de texto (ni Poppins) tiene un glifo para dibujarlo
        // — dompdf no soporta fuentes de emoji a color. Cada emoji suele
        // ser 2 codepoints (el pictograma + un selector de variación
        // U+FE0F que fuerza presentación emoji), por eso salía como "??"
        // en vez de un solo "?". Se quitan (vacío, no bullet — no son
        // viñetas de lista, son decoración a mitad de frase).
        $html = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $html);

        return $html;
    }
}
