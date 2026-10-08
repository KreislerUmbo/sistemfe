<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corre en /tenancy/assets/{path} (TenantAssetsController, ver
 * TenancyServiceProvider::configureTenantAssetsMiddleware()). Ese endpoint sirve
 * los archivos del disco 'public' del tenant en el MISMO dominio que el panel, así
 * que un .html/.svg subido antes de validar el tipo (auditoría de seguridad
 * 08-oct-2026) se abría como página del sistema y podía leer el token de sesión.
 *
 * Validar al subir (App\Rules\ArchivoSubido) cierra la puerta hacia adelante; esto
 * neutraliza lo que ya esté guardado: todo lo que no sea imagen raster o PDF se
 * entrega como descarga y con CSP sandbox (sin scripts ni acceso al origen).
 */
class ProtegerArchivosServidos
{
    private const TIPOS_INLINE = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $tipo = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0]));
        if ($response->isSuccessful() && ! in_array($tipo, self::TIPOS_INLINE, true)) {
            $response->headers->set('Content-Security-Policy', 'sandbox');
            $response->headers->set('Content-Disposition', 'attachment');
        }

        return $response;
    }
}
