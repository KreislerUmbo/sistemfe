<?php

namespace Tests\Unit;

use App\Services\AgenciaViajes\CondicionesGeneralesPdfService;
use PHPUnit\Framework\TestCase;

// Rediseño del PDF de condiciones generales (09-oct-2026):
// prepararContenido() ordena el HTML de Quill para que se lea como
// documento, sin tocar el dato guardado. Casos tomados del texto real de
// agencia-demo.
class CondicionesGeneralesContenidoTest extends TestCase
{
    public function test_encabezado_inicial_pasa_a_ser_el_titulo_del_documento(): void
    {
        $r = CondicionesGeneralesPdfService::prepararContenido(
            '<h2><strong>CONDICIONES GENERALES DEL SERVICIO DKM XPLORE</strong></h2><p>Texto.</p>'
        );

        $this->assertSame('CONDICIONES GENERALES DEL SERVICIO DKM XPLORE', $r['titulo']);
        $this->assertSame('<p>Texto.</p>', $r['html']);
    }

    public function test_sin_encabezado_inicial_usa_el_titulo_por_defecto(): void
    {
        $r = CondicionesGeneralesPdfService::prepararContenido('<p>Texto.</p><h2>Otro</h2>');

        $this->assertSame('Condiciones generales del servicio', $r['titulo']);
        $this->assertStringContainsString('<h2>Otro</h2>', $r['html']);
    }

    public function test_parrafo_corto_entero_en_negrita_es_titulo_de_seccion(): void
    {
        $r = CondicionesGeneralesPdfService::prepararContenido('<p><strong>TARIFAS</strong></p><ul><li>Uno</li></ul>');

        $this->assertSame('<div class="cg-seccion-titulo">TARIFAS</div><ul><li>Uno</li></ul>', $r['html']);
    }

    public function test_parrafo_en_negrita_que_termina_en_dos_puntos_es_subtitulo(): void
    {
        $r = CondicionesGeneralesPdfService::prepararContenido('<p><strong>Los datos deben ser enviados a:</strong></p>');

        $this->assertSame('<div class="cg-subtitulo">Los datos deben ser enviados a:</div>', $r['html']);
    }

    public function test_parrafo_largo_en_negrita_es_declaracion_destacada(): void
    {
        $texto = 'Al confirmar la reserva, el cliente declara haber leído y aceptado las condiciones del servicio.';
        $r = CondicionesGeneralesPdfService::prepararContenido("<p><strong>{$texto}</strong></p>");

        $this->assertSame("<div class=\"cg-destacado\">{$texto}</div>", $r['html']);
    }

    public function test_parrafo_con_negrita_parcial_queda_como_esta(): void
    {
        $html = '<p>Son <strong>no reembolsables</strong> y están sujetas a disponibilidad.</p>';

        $this->assertSame($html, CondicionesGeneralesPdfService::prepararContenido($html)['html']);
    }

    public function test_dos_negritas_en_el_mismo_parrafo_no_se_vuelven_titulo(): void
    {
        $html = '<p><strong>Semana Santa</strong>, <strong>Navidad</strong></p>';

        $this->assertSame($html, CondicionesGeneralesPdfService::prepararContenido($html)['html']);
    }

    public function test_parrafos_vacios_de_separacion_se_quitan(): void
    {
        $r = CondicionesGeneralesPdfService::prepararContenido('<p>A</p><p><br></p><p> &nbsp; </p><p>B</p>');

        $this->assertSame('<p>A</p><p>B</p>', $r['html']);
    }

    public function test_sin_contenido_muestra_aviso(): void
    {
        foreach ([null, '', '<p><br></p>'] as $vacio) {
            $r = CondicionesGeneralesPdfService::prepararContenido($vacio);
            $this->assertSame('Condiciones generales del servicio', $r['titulo']);
            $this->assertSame('<p>Sin condiciones configuradas todavía.</p>', $r['html']);
        }
    }
}
