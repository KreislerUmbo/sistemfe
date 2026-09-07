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
}
