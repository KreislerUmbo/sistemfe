<?php

namespace Tests\Unit;

use App\Services\TextoFormatoService;
use PHPUnit\Framework\TestCase;

class TextoFormatoServiceTest extends TestCase
{
    public function test_capitaliza_texto_todo_en_minuscula(): void
    {
        $this->assertSame('Hotel Rioja', TextoFormatoService::capitalizarNombrePropio('hotel rioja'));
    }

    public function test_capitaliza_texto_todo_en_mayuscula(): void
    {
        $this->assertSame('Hotel Rioja', TextoFormatoService::capitalizarNombrePropio('HOTEL RIOJA'));
    }

    public function test_deja_conectores_en_minuscula_salvo_la_primera_palabra(): void
    {
        $this->assertSame(
            'Traslado de la Selva',
            TextoFormatoService::capitalizarNombrePropio('traslado de la selva')
        );
    }

    public function test_capitaliza_conector_cuando_es_la_primera_palabra(): void
    {
        $this->assertSame('La Casa Grande', TextoFormatoService::capitalizarNombrePropio('la casa grande'));
    }

    public function test_recorta_espacios_al_inicio_y_final(): void
    {
        $this->assertSame('Hotel Rioja', TextoFormatoService::capitalizarNombrePropio('  hotel rioja  '));
    }

    public function test_colapsa_espacios_multiples_internos(): void
    {
        $this->assertSame('Hotel Rioja', TextoFormatoService::capitalizarNombrePropio('hotel    rioja'));
    }

    public function test_null_devuelve_null(): void
    {
        $this->assertNull(TextoFormatoService::capitalizarNombrePropio(null));
    }

    public function test_string_vacio_devuelve_string_vacio(): void
    {
        $this->assertSame('', TextoFormatoService::capitalizarNombrePropio(''));
    }

    public function test_string_solo_espacios_devuelve_vacio(): void
    {
        $this->assertSame('', TextoFormatoService::capitalizarNombrePropio('   '));
    }

    public function test_palabra_unica(): void
    {
        $this->assertSame('Rioja', TextoFormatoService::capitalizarNombrePropio('RIOJA'));
    }

    // ── sanitizarHtmlParaPdf() — bug real 06-sep-2026 en el PDF de cotización ──

    public function test_sanitiza_vineta_de_wingdings_pegada_desde_word(): void
    {
        // U+F0FC real, encontrado con un dump de bytes contra el
        // contenido real de un tour de agencia-demo — "?? Transporte
        // turístico." en el PDF venía de exactamente este carácter.
        $conViñetaWingdings = "¿QUÉ INCLUYE?\n\u{F0FC}\tTransporte turístico.";

        $resultado = TextoFormatoService::sanitizarHtmlParaPdf($conViñetaWingdings);

        $this->assertStringContainsString('•', $resultado);
        $this->assertStringContainsString('Transporte turístico', $resultado);
        $this->assertStringNotContainsString("\u{F0FC}", $resultado);
    }

    public function test_quita_emoji_real_que_ninguna_fuente_de_texto_puede_dibujar(): void
    {
        // 🏔️ = U+1F3D4 (pictograma) + U+FE0F (selector de variación) — 2
        // codepoints, por eso salía como "??" (uno por codepoint) en vez
        // de un solo "?". No es viñeta, se quita entero (no se reemplaza
        // por bullet).
        $conEmoji = "empezar el recorrido. \u{1F3D4}\u{FE0F} Ubicación";

        $resultado = TextoFormatoService::sanitizarHtmlParaPdf($conEmoji);

        $this->assertSame('empezar el recorrido.  Ubicación', $resultado);
    }

    public function test_no_toca_texto_normal_con_acentos_y_puntuacion(): void
    {
        $textoNormal = '¿Qué incluye? Traslado ida y vuelta — 2 noches en Cusco.';

        $this->assertSame($textoNormal, TextoFormatoService::sanitizarHtmlParaPdf($textoNormal));
    }

    public function test_null_devuelve_null_en_sanitizar(): void
    {
        $this->assertNull(TextoFormatoService::sanitizarHtmlParaPdf(null));
    }

    // ── textoLibreParaPdf() — editor de texto enriquecido, 07-sep-2026 ──

    public function test_texto_plano_legacy_reconstruye_lista_con_vinetas(): void
    {
        // Dato cargado ANTES del editor de texto enriquecido — sin
        // ninguna etiqueta HTML, solo "\n" literales. Bug real: sin este
        // fallback, renderizar crudo con nl2br() perdía las viñetas que
        // antes daba gratis el <li> con list-style por defecto.
        $textoPlano = "Transporte turístico.\nGuía turístico bilingüe.\nAlmuerzo buffet.";

        $resultado = TextoFormatoService::textoLibreParaPdf($textoPlano);

        $this->assertSame(
            '<ul class="lista-simple"><li>Transporte turístico.</li><li>Guía turístico bilingüe.</li><li>Almuerzo buffet.</li></ul>',
            $resultado
        );
    }

    public function test_texto_plano_con_lineas_vacias_las_descarta(): void
    {
        $textoPlano = "Primera línea.\n\n\nSegunda línea.\n";

        $resultado = TextoFormatoService::textoLibreParaPdf($textoPlano);

        $this->assertSame(
            '<ul class="lista-simple"><li>Primera línea.</li><li>Segunda línea.</li></ul>',
            $resultado
        );
    }

    public function test_html_de_quill_se_renderiza_tal_cual(): void
    {
        // Dato nuevo (RichTextEditor/Quill) — ya trae su propia estructura,
        // no debe envolverse en un <ul><li> adicional.
        $htmlQuill = '<p>Incluye <strong>desayuno</strong> todos los días.</p><ul><li>Wifi gratis</li></ul>';

        $resultado = TextoFormatoService::textoLibreParaPdf($htmlQuill);

        $this->assertSame($htmlQuill, $resultado);
    }

    public function test_html_de_quill_con_salto_de_linea_pegado_al_cierre_de_etiqueta_se_descarta(): void
    {
        // Bug real 07-sep-2026 ("las viñetas aparecen duplicado"): pegar
        // contenido de Word en Quill deja "\n" sueltos justo antes del
        // cierre de un <li> ya armado (ver dato real
        // "<li>Traslados de entrada y salida.\n</li>") — nl2br() los
        // convertía en un <br> visible, dejando una línea en blanco
        // debajo de cada viñeta. Pegado a un borde de etiqueta = puro
        // relleno, se descarta entero (ni espacio ni <br>).
        $htmlConSalto = "<ul><li>Traslados de entrada y salida.\n</li></ul>";

        $resultado = TextoFormatoService::textoLibreParaPdf($htmlConSalto);

        $this->assertStringNotContainsString('<br', $resultado);
        $this->assertSame('<ul><li>Traslados de entrada y salida.</li></ul>', $resultado);
    }

    public function test_html_de_quill_con_salto_de_linea_en_medio_de_texto_se_preserva_como_br(): void
    {
        // Caso real distinto (07-sep-2026): un detalle de vuelo cargado
        // como texto plano ANTES del editor de texto enriquecido, abierto
        // por primera vez en Quill sin que el vendedor tocara nada, queda
        // envuelto en un único <p> — los "\n" entre cada línea de vuelo
        // NO tocan ningún borde de etiqueta, son el único indicio de
        // salto de línea que queda. Acá SÍ debe verse como <br> real —
        // tratarlo igual que el caso de arriba pegaría todos los vuelos
        // en un solo párrafo corrido (regresión real, encontrada
        // generando el PDF con este mismo dato).
        $htmlConSalto = "<p>21 Agosto TPPLIM Sale 10.40\n21 Agosto LIMCUZ Sale 13.45</p>";

        $resultado = TextoFormatoService::textoLibreParaPdf($htmlConSalto);

        $this->assertStringContainsString('<br', $resultado);
        $this->assertStringContainsString('21 Agosto TPPLIM Sale 10.40', $resultado);
        $this->assertStringContainsString('21 Agosto LIMCUZ Sale 13.45', $resultado);
    }

    public function test_texto_vacio_devuelve_string_vacio_en_texto_libre(): void
    {
        $this->assertSame('', TextoFormatoService::textoLibreParaPdf(''));
        $this->assertSame('', TextoFormatoService::textoLibreParaPdf('   '));
        $this->assertSame('', TextoFormatoService::textoLibreParaPdf(null));
    }

    public function test_quill_vacio_a_la_vista_devuelve_string_vacio(): void
    {
        // Quill nunca deja el contenido en '' cuando está "vacío" a la
        // vista — emite '<p><br></p>'.
        $this->assertSame('', TextoFormatoService::textoLibreParaPdf('<p><br></p>'));
    }

    public function test_texto_libre_sanitiza_vineta_de_wingdings(): void
    {
        $conViñetaWingdings = "\u{F0FC} Transporte turístico.";

        $resultado = TextoFormatoService::textoLibreParaPdf($conViñetaWingdings);

        $this->assertStringContainsString('• Transporte turístico.', $resultado);
    }
}
