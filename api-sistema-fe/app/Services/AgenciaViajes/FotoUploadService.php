<?php

namespace App\Services\AgenciaViajes;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;

// Servicio compartido de carga de fotos — usado por DestinoAtractivoController
// y PaquetePlantillaController, ambos con el mismo patrón de columna `fotos`
// (array json de paths en el disco 'public'). Antes de esta sesión cada
// controller subía el archivo tal cual, sin límite de peso/cantidad ni
// redimensionado — ver plan de la sesión "destinos-atractivos-form".
class FotoUploadService
{
    public const MAX_KB_POR_FOTO = 5120; // 5MB
    public const MAX_FOTOS_TOTAL = 10;
    public const MAX_LADO_PX = 1920;
    public const CALIDAD_JPEG = 80;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = ImageManager::gd();
    }

    /**
     * Procesa un lote de fotos nuevas: valida cantidad total (todo-o-nada),
     * valida peso por archivo (rechazo individual, sin bloquear el resto del
     * lote), redimensiona/reorienta/recomprime cada una que pase, y la
     * guarda en $directorio dentro del disco 'public'.
     *
     * @param  UploadedFile[]  $archivos
     * @return array{paths: string[], rechazadas: array<array{nombre: string, motivo: string}>}
     *
     * @throws ValidationException si (fotos existentes + fotos nuevas) supera el máximo permitido.
     */
    public function procesarLote(array $archivos, string $directorio, int $fotosExistentes): array
    {
        $totalIntentado = $fotosExistentes + count($archivos);

        if ($totalIntentado > self::MAX_FOTOS_TOTAL) {
            throw ValidationException::withMessages([
                'fotos' => 'El registro ya tiene '.$fotosExistentes.' foto(s) y se están intentando agregar '
                    .count($archivos).' más, superando el máximo de '.self::MAX_FOTOS_TOTAL.' fotos permitidas.',
            ]);
        }

        $paths = [];
        $rechazadas = [];

        foreach ($archivos as $archivo) {
            if ($archivo->getSize() > self::MAX_KB_POR_FOTO * 1024) {
                $rechazadas[] = [
                    'nombre' => $archivo->getClientOriginalName(),
                    'motivo' => 'Supera el máximo de '.round(self::MAX_KB_POR_FOTO / 1024).'MB por foto.',
                ];

                continue;
            }

            $paths[] = $this->procesarUnaFoto($archivo, $directorio);
        }

        return ['paths' => $paths, 'rechazadas' => $rechazadas];
    }

    private function procesarUnaFoto(UploadedFile $archivo, string $directorio): string
    {
        $imagen = $this->manager->read($archivo->getRealPath());

        // orient() ANTES de scaleDown(): las fotos de celular traen la
        // rotación como metadato EXIF, no rotado en el archivo — sin esto
        // quedan guardadas de costado o invertidas.
        $imagen->orient()->scaleDown(width: self::MAX_LADO_PX, height: self::MAX_LADO_PX);

        $codificada = $imagen->toJpeg(quality: self::CALIDAD_JPEG);

        $nombre = trim($directorio, '/').'/'.uniqid('foto_', true).'.jpg';
        Storage::disk('public')->put($nombre, (string) $codificada);

        return $nombre;
    }
}
