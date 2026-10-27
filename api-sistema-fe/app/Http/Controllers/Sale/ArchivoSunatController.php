<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Models\Sale\Note;
use App\Models\Sale\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

// T-069: descargar el XML y el CDR de una venta o una nota. Antes la pantalla
// abría la dirección guardada ("/storage/cdrs/x.zip"), que nginx sirve desde
// la carpeta CENTRAL; el archivo vive en la del negocio
// (storage/tenant{id}/app/public/...): 404 en todos los tenants salvo umbo
// local, que tenía copias a mano. Mismo patrón que el PDF
// (SaleController::pdfSignedUrl()): dirección firmada de 10 minutos, y el
// archivo se lee del disco del negocio de la petición.
class ArchivoSunatController extends Controller
{
    private const DOCUMENTOS = ['venta' => Sale::class, 'nota' => Note::class];

    private const ARCHIVOS = ['xml', 'cdr'];

    // Dentro de auth:api.
    public function urlFirmada(Request $request, string $documento, string $id)
    {
        $archivo = (string) $request->query('archivo');
        if (! in_array($archivo, self::ARCHIVOS, true)) {
            return response()->json(['message' => 'Archivo inválido: xml o cdr.'], 422);
        }

        $comprobante = $this->comprobante($documento, $id);
        if (! $comprobante->{$archivo}) {
            return response()->json(['message' => 'Este comprobante todavía no tiene ' . strtoupper($archivo) . '.'], 404);
        }

        $url = URL::temporarySignedRoute('sunat.archivo', now()->addMinutes(10), [
            'documento' => $documento,
            'id' => $id,
            'archivo' => $archivo,
        ]);

        return response()->json(['url' => $url]);
    }

    // Ruta firmada (sin login): el navegador la abre en otra pestaña.
    public function descargar(string $documento, string $id, string $archivo)
    {
        abort_unless(in_array($archivo, self::ARCHIVOS, true), 404);

        $ruta = self::rutaEnDisco($this->comprobante($documento, $id)->{$archivo});
        if (! $ruta || ! Storage::disk('public')->exists($ruta)) {
            abort(404, 'El archivo ya no está en el servidor.');
        }

        return Storage::disk('public')->download($ruta, basename($ruta));
    }

    // Lo guardado es la URL vieja ("/storage/cdrs/x.zip"); en el disco del
    // negocio es "cdrs/x.zip". Nunca sale de esa carpeta.
    public static function rutaEnDisco(?string $guardado): ?string
    {
        if (! $guardado) {
            return null;
        }

        $pos = strpos($guardado, '/storage/');
        $ruta = $pos === false ? ltrim($guardado, '/') : substr($guardado, $pos + strlen('/storage/'));

        return $ruta === '' || str_contains($ruta, '..') ? null : $ruta;
    }

    private function comprobante(string $documento, string $id): Sale|Note
    {
        abort_unless(isset(self::DOCUMENTOS[$documento]), 404);

        return self::DOCUMENTOS[$documento]::findOrFail($id);
    }
}
