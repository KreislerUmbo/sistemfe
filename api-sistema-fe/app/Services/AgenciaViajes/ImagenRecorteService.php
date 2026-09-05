<?php

namespace App\Services\AgenciaViajes;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

// Recorte centrado 4:3 para el PDF de cotización
// (plan-mejora-pdf-cotizacion-cliente.md §4.5) — dompdf no soporta
// `object-fit`, así que el recorte se hace en el servidor, no en CSS
// (confirmado en Paso 0 del brief de ejecución).
//
// Ajuste sobre el diseño original: en vez de generar el recorte al subir
// la foto y persistir su ruta en columnas nuevas (`ruta_recorte_portada`/
// `ruta_recorte_galeria`), se genera al vuelo en cada render del PDF y se
// devuelve directo como data URI (mismo motivo que
// StorageUrl::resolveParaPdf — DomPDF corre con enable_remote=false, así
// que una URL no cargaría igual). Evita columnas que quedan obsoletas si
// se reemplaza la foto original, y el volumen por documento es bajo
// (unas pocas fotos por PDF) — no amerita cachear a disco.
//
// Un solo recorte 4:3 sirve tanto para el espacio de portada como para el
// de galería (misma proporción, solo cambia el ancho en el CSS de la
// plantilla) — no hace falta generar dos variantes por foto.
class ImagenRecorteService
{
    private const ANCHO_PX = 640;
    private const ALTO_PX = 480; // 4:3
    private const CALIDAD_JPEG = 80;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = ImageManager::gd();
    }

    public function recortar4x3ParaPdf(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $imagen = $this->manager->read(Storage::disk('public')->path($path));
        $imagen->orient()->cover(self::ANCHO_PX, self::ALTO_PX);

        return 'data:image/jpeg;base64,' . base64_encode((string) $imagen->toJpeg(quality: self::CALIDAD_JPEG));
    }

    /**
     * @param  string[]  $paths
     * @return string[] Solo las que existen y se pudieron recortar (sin nulls).
     */
    public function recortarVariasParaPdf(array $paths): array
    {
        return array_values(array_filter(array_map(
            fn (string $path) => $this->recortar4x3ParaPdf($path),
            $paths
        )));
    }
}
