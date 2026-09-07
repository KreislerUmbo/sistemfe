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

    // Bug real (07-sep-2026, reportado por el usuario — el PDF seguía
    // saliendo en Helvetica pese a la fuente ya registrada y probada en
    // aislado): storage_path() está REESCRITO por tenant
    // (FilesystemTenancyBootstrapper, ver CLAUDE.md) — dentro de una
    // request con tenant activo (el caso real, todo PDF se genera con un
    // tenant resuelto) resuelve a storage/tenant{slug}/fonts/poppins/...,
    // que no existe, así que is_file() fallaba en silencio y ningún peso
    // se registraba nunca. Confirmado con
    // storage_path('fonts/poppins/Poppins-Regular.ttf') dentro de
    // $tenant->run() → resolvió literal a
    // ".../storage/tenantagencia-demo/fonts/poppins/...".
    // Poppins es un recurso COMPARTIDO (no un dato de negocio de un
    // tenant) — mismo criterio que certificate-demo.pem (CLAUDE.md,
    // Fase B.2): se referencia con base_path(), que NUNCA se reescribe
    // por tenant, en vez de storage_path().
    //
    // Se llama sobre el Dompdf real (Pdf::loadView(...)->getDomPDF()) ANTES
    // de loadView(): dompdf resuelve font-family contra las fuentes
    // conocidas durante el parseo del CSS (loadHtml(), disparado por
    // loadView()), no en el render() posterior — confirmado con el PDF
    // real (grep de "Poppins" dentro de los bytes del archivo) que
    // registrar después de loadView() no tenía efecto.
    public static function registrarPoppins(Dompdf $dompdf): void
    {
        $directorio = base_path('storage/fonts/poppins');

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
