<?php

namespace App\Rules;

/**
 * Reglas de validación compartidas para archivos subidos que se guardan en el
 * disco 'public' (servidos por /tenancy/assets en el mismo dominio que el panel).
 *
 * Auditoría de seguridad 08-oct-2026: varias subidas aceptaban cualquier archivo
 * y putFile() le pone la extensión según el contenido — un .html o .svg subido
 * como "imagen" se servía como página del propio sistema (XSS que roba el token).
 * `mimes` también se valida por contenido, no por el nombre del archivo.
 * Sin SVG a propósito: puede llevar scripts.
 */
final class ArchivoSubido
{
    public const MAXIMO_KB = 10240; // 10 MB (nginx acepta hasta 25 MB)

    public const MENSAJES = [
        '*.mimes' => 'El archivo debe ser una imagen JPG, PNG, WEBP o GIF.',
        '*.max' => 'El archivo no puede superar los 10 MB.',
    ];

    /** Imagen (logo, avatar, foto de producto/categoría). */
    public static function imagen(): array
    {
        return ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:' . self::MAXIMO_KB];
    }

    /** Imagen o PDF (comprobantes y adjuntos). */
    public static function imagenOPdf(): array
    {
        return ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif,pdf', 'max:' . self::MAXIMO_KB];
    }
}
