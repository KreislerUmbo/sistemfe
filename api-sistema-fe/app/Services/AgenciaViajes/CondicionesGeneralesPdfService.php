<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CuentaBancaria;
use App\Services\HoraPeru;
use App\Services\PdfFontService;
use App\Services\TextoFormatoService;

// PDF de condiciones generales del servicio — documento separado del PDF
// comercial de la alternativa (decisión de diseño confirmada), mismo
// contenido para toda cotización del tenant, armado en vivo desde
// configuracion_agencia.condiciones_generales_servicio (HTML de Quill) +
// cuentas bancarias.
//
// Rediseño (09-oct-2026, pedido del usuario: "no se ve profesional, tiene
// que ser similar al PDF de servicios con su membrete"): mismo membrete fijo
// que la cotización (MembreteAgenciaPdfService + partials), Poppins y el
// mismo estilo de secciones/tabla de pagos. Antes era una plantilla aparte
// en Arial, sin membrete, y el logo nunca cargaba (usaba
// StorageUrl::resolve(), que no funciona dentro de DomPDF).
class CondicionesGeneralesPdfService
{
    private const TITULO_POR_DEFECTO = 'Condiciones generales del servicio';

    // Un párrafo en negrita más largo que esto es una declaración, no un
    // título de sección (ver prepararContenido()).
    private const LARGO_MAXIMO_TITULO = 70;

    public function __construct(private MembreteAgenciaPdfService $membrete)
    {
    }

    public function generar()
    {
        $config = ConfiguracionAgencia::first();
        $membrete = $this->membrete->datos();
        $contenido = self::prepararContenido($config?->condiciones_generales_servicio);
        $emision = HoraPeru::ahora()->format('d/m/Y');
        $agencia = $membrete['empresa']?->razon_social_comercial ?? $membrete['empresa']?->razon_social ?? '';

        // Poppins se registra ANTES de loadView() — mismo motivo que en
        // AlternativaPdfService::generar() (dompdf resuelve font-family al
        // parsear el CSS).
        $pdf = app('dompdf.wrapper');
        PdfFontService::registrarPoppins($pdf->getDomPDF());

        $pdf = $pdf->loadView('pdf.agencia-viajes.condiciones-generales', [
            ...$membrete,
            'titulo' => $contenido['titulo'],
            'contenidoHtml' => $contenido['html'],
            'emision' => $emision,
            'cuentasBancarias' => CuentaBancaria::where('activo', true)->orderBy('orden')->orderBy('id')->get(),
            'textoLegal' => trim('Condiciones generales del servicio' . ($agencia ? " · {$agencia}" : '') . " · Emitido el {$emision}"),
        ]);

        return $pdf->download('Condiciones-Generales-del-Servicio.pdf');
    }

    /**
     * Ordena el HTML de Quill para que se lea como documento:
     * - Un encabezado (h1/h2/h3) al inicio se usa como título del documento
     *   (en agencia-demo el texto arranca con "CONDICIONES GENERALES DEL
     *   SERVICIO DKM XPLORE" — sin esto salía dos veces el mismo título).
     * - Un párrafo que es ENTERO negrita ("<p><strong>TARIFAS</strong></p>",
     *   la forma en que los vendedores escriben los subtítulos en el
     *   editor) se vuelve título de sección, con el mismo estilo que las
     *   secciones de la cotización.
     * - Los párrafos vacíos ("<p><br></p>", usados como separador) se
     *   quitan: el espaciado lo pone el estilo de sección.
     * Nunca toca el dato guardado, solo lo que se imprime.
     *
     * @return array{titulo: string, html: string}
     */
    public static function prepararContenido(?string $html): array
    {
        $html = trim(TextoFormatoService::sanitizarHtmlParaPdf($html) ?? '');

        if (trim(strip_tags($html)) === '') {
            return ['titulo' => self::TITULO_POR_DEFECTO, 'html' => '<p>Sin condiciones configuradas todavía.</p>'];
        }

        $html = preg_replace('#<p[^>]*>(?:\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html);

        $titulo = self::TITULO_POR_DEFECTO;
        if (preg_match('#^\s*<(h[1-3])[^>]*>(.*?)</\1>#is', $html, $m)) {
            $textoEncabezado = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($textoEncabezado !== '') {
                $titulo = $textoEncabezado;
                $html = substr($html, strlen($m[0]));
            }
        }

        // Solo si TODO el párrafo es un único <strong> (sin otro <strong>
        // ni texto suelto) — un párrafo normal con una palabra en negrita
        // queda como está. Según el texto (casos reales de agencia-demo):
        // - termina en ":" ("LOS DATOS DEBEN SER ENVIADOS A:") → subtítulo
        //   que presenta la lista de abajo, sin línea de sección;
        // - es largo (una declaración: "AL CONFIRMAR LA RESERVA, EL
        //   CLIENTE DECLARA...") → recuadro destacado, no un título;
        // - si no → título de sección.
        $html = preg_replace_callback(
            '#<p[^>]*>\s*<strong>((?:(?!</?strong\b|</?p\b).)+?)</strong>\s*(?:<br\s*/?>)?\s*</p>#is',
            function ($m) {
                $interno = trim($m[1]);
                $texto = trim(html_entity_decode(strip_tags($interno), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                $clase = match (true) {
                    mb_strlen($texto) > self::LARGO_MAXIMO_TITULO => 'cg-destacado',
                    str_ends_with($texto, ':') => 'cg-subtitulo',
                    default => 'cg-seccion-titulo',
                };

                return "<div class=\"{$clase}\">{$interno}</div>";
            },
            $html
        );

        return ['titulo' => $titulo, 'html' => trim($html)];
    }
}
