<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\AfiliacionTurismo;
use App\Models\AgenciaViajes\ConfiguracionAgenciaPdf;
use App\Models\Company;
use App\Services\StorageUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

// Membrete de la agencia para los PDF que se le entregan al cliente
// (09-oct-2026, pedido del usuario: las condiciones generales debían verse
// "igual de profesionales" que la cotización). Extraído sin cambios de
// lógica de AlternativaPdfService — afiliacionesParaMostrar()/
// alturaHeaderMm()/alturaFooterMm()/alturaBandaCompletaMm() — para que la
// cotización y las condiciones generales usen EXACTAMENTE el mismo header y
// footer fijos (vistas pdf/agencia-viajes/partials/membrete-*.blade.php).
class MembreteAgenciaPdfService
{
    // Hallazgo del usuario (06-sep-2026) — hoja membretada real: el
    // header/footer debe quedar FIJO arriba/abajo de CADA página, no
    // subir/bajar con el contenido. dompdf soporta esto con
    // position:fixed dentro del margen reservado por @page (mismo truco
    // ya usado en reporte-operativo.blade.php) — pero el margen de @page
    // es un valor fijo en el CSS, así que hay que calcular acá cuánto
    // alto reservar según lo que realmente se va a imprimir arriba/abajo.
    //
    // Con imagen custom: la agencia sube su membrete con la proporción
    // que quiera (ver los 2 casos reales de DKM Xplore) — se mide el
    // archivo real y se calcula qué alto le corresponde al ancho
    // completo de la hoja A4 (el membrete hace bleed hasta el borde
    // físico, ver ANCHO_PAGINA_A4_MM en alturaBandaCompletaMm()).
    //
    // Sin imagen custom: el header/footer generado desde
    // ConfiguracionAgenciaPdf tiene un alto predecible (logo + 2 líneas
    // de contacto, +eslogan, +franja de afiliaciones) — reserva fija por
    // bloque presente, no medida en píxeles porque no hay ninguna imagen
    // que medir.
    private const ANCHO_PAGINA_A4_MM = 210.0;

    /**
     * Variables que esperan los partials del membrete (estilos, header y
     * footer). Todas las URLs vía resolveParaPdf(): DomPDF corre con
     * enable_remote=false, la URL de navegador de resolve() nunca carga.
     */
    public function datos(): array
    {
        $configPdf = ConfiguracionAgenciaPdf::actual();
        $empresa = Company::first();
        $afiliaciones = $this->afiliacionesParaMostrar($configPdf);

        return [
            'configPdf' => $configPdf,
            'empresa' => $empresa,
            'logoUrl' => StorageUrl::resolveParaPdf($empresa?->logo_horizontal),
            'headerCustomUrl' => StorageUrl::resolveParaPdf($configPdf->imagen_header_custom),
            'footerCustomUrl' => StorageUrl::resolveParaPdf($configPdf->imagen_footer_custom),
            'afiliaciones' => $afiliaciones,
            // Hallazgo del usuario (06-sep-2026): el membrete es fijo
            // (position:fixed dentro del margen de @page) — el alto
            // reservado se calcula acá porque la imagen la sube la agencia
            // con la proporción que quiera.
            'alturaHeaderMm' => $this->alturaHeaderMm($configPdf, $afiliaciones),
            'alturaFooterMm' => $this->alturaFooterMm($configPdf),
        ];
    }

    // plan §4.3 — solo arma la franja si mostrar_afiliaciones=true Y la
    // agencia marcó al menos una. AfiliacionTurismo vive en la base
    // central (CentralConnection) — un solo whereIn() para resolver todas
    // las marcadas, sin N+1.
    private function afiliacionesParaMostrar(ConfiguracionAgenciaPdf $configPdf): Collection
    {
        if (! $configPdf->mostrar_afiliaciones || ! $configPdf->exists) {
            return collect();
        }

        $marcadas = $configPdf->afiliaciones()->get();
        if ($marcadas->isEmpty()) {
            return collect();
        }

        $catalogo = AfiliacionTurismo::whereIn('id', $marcadas->pluck('afiliacion_id'))->get()->keyBy('id');

        return $marcadas->map(function ($m) use ($catalogo) {
            $afiliacion = $catalogo->get($m->afiliacion_id);

            return $afiliacion ? [
                'nombre' => $afiliacion->nombre,
                'logo' => StorageUrl::resolveParaPdf($afiliacion->logo_path),
            ] : null;
        })->filter()->values();
    }

    private function alturaHeaderMm(ConfiguracionAgenciaPdf $configPdf, Collection $afiliaciones): float
    {
        if ($configPdf->imagen_header_custom) {
            return $this->alturaBandaCompletaMm($configPdf->imagen_header_custom);
        }

        $altura = 28.0; // logo + nombre comercial + RUC/teléfono/email
        if (! empty($configPdf->eslogan)) {
            $altura += 4.0;
        }
        if ($afiliaciones->isNotEmpty()) {
            $altura += 10.0;
        }

        return $altura;
    }

    private function alturaFooterMm(ConfiguracionAgenciaPdf $configPdf): float
    {
        if ($configPdf->imagen_footer_custom) {
            // +14mm: la línea legal del pie (footer-legal) va SIEMPRE arriba
            // del membrete custom, es contenido legal, no branding — el
            // override total del plan §4.2 solo reemplaza eslogan/redes, no
            // esa línea.
            return $this->alturaBandaCompletaMm($configPdf->imagen_footer_custom) + 14.0;
        }

        return empty($configPdf->redes_sociales) ? 16.0 : 22.0;
    }

    private function alturaBandaCompletaMm(string $path): float
    {
        if (! Storage::disk('public')->exists($path)) {
            return 20.0;
        }

        // getimagesize() alcanza acá — solo hace falta el ancho/alto real
        // del archivo, no manipular la imagen (eso ya lo hace
        // ImagenRecorteService para las fotos de tour/hotel).
        $medidas = @getimagesize(Storage::disk('public')->path($path));
        if (! $medidas || $medidas[0] <= 0) {
            return 20.0;
        }

        return round(self::ANCHO_PAGINA_A4_MM * ($medidas[1] / $medidas[0]), 1);
    }
}
