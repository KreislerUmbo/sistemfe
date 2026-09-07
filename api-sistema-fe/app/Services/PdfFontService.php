<?php

namespace App\Services;

use Dompdf\Dompdf;

// Registro de fuentes propias para dompdf — pedido del usuario (06-sep-2026):
// el PDF de cotización debe usar Poppins, la misma fuente que ya usan en sus
// documentos Word, en vez de Arial/Helvetica (el default de dompdf).
//
// Archivos reales descargados del repo oficial de Google Fonts
// (github.com/google/fonts/ofl/poppins — licencia SIL Open Font License,
// libre para embeber) a storage/fonts/poppins/ — no hace falta que el
// usuario provea sus propios .ttf, Poppins es una fuente pública, la misma
// en cualquier Word/PDF/sitio que la use.
//
// Cada peso se registra como una FAMILIA propia (Poppins-Medium,
// Poppins-SemiBold) en vez de depender de font-weight numérico (500/600)
// sobre una sola familia 'Poppins' — el matching de dompdf para pesos
// numéricos fuera de normal/bold no es confiable; pedir la familia exacta
// desde el CSS de la plantilla es más seguro.
class PdfFontService
{
    private const FUENTES = [
        ['family' => 'Poppins', 'weight' => 'normal', 'style' => 'normal', 'archivo' => 'Poppins-Regular.ttf'],
        ['family' => 'Poppins', 'weight' => 'bold', 'style' => 'normal', 'archivo' => 'Poppins-Bold.ttf'],
        ['family' => 'Poppins-Medium', 'weight' => 'normal', 'style' => 'normal', 'archivo' => 'Poppins-Medium.ttf'],
        ['family' => 'Poppins-SemiBold', 'weight' => 'normal', 'style' => 'normal', 'archivo' => 'Poppins-SemiBold.ttf'],
    ];

    // Se llama sobre el Dompdf real (Pdf::loadView(...)->getDomPDF()) DESPUÉS
    // de loadView() pero ANTES de download()/stream() — dompdf recién
    // resuelve fuentes durante render() (disparado por download()), así que
    // registrar acá en el medio alcanza. registerFont() cachea el resultado
    // en storage/fonts (mismo directorio que font_dir/font_cache de
    // config/dompdf.php) — solo convierte el .ttf la primera vez.
    public static function registrarPoppins(Dompdf $dompdf): void
    {
        $directorio = storage_path('fonts/poppins');

        foreach (self::FUENTES as $fuente) {
            $ruta = $directorio.DIRECTORY_SEPARATOR.$fuente['archivo'];
            if (! is_file($ruta)) {
                continue;
            }

            $dompdf->getFontMetrics()->registerFont(
                ['family' => $fuente['family'], 'weight' => $fuente['weight'], 'style' => $fuente['style']],
                $ruta
            );
        }
    }
}
