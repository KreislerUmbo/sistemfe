<?php

namespace App\Models\AgenciaViajes\Concerns;

use App\Services\StorageUrl;

// T-020 — fotos de hotel con tipo: la columna `fotos` guarda {path, tipo_foto}[]
// tanto en opciones_hotel como en proveedores. Un solo lugar para normalizar y resolver
// (antes vivía en OpcionHotel). Bug real del 05-sep-2026: un controller llamó
// StorageUrl::resolveMuchas($hotel->fotos) directo y reventó al cambiar la forma de string[]
// a {path, tipo_foto}[] — cualquier controller que resuelva fotos debe usar ESTA normalización.
//
// Cada modelo dice su tipo por defecto con tipoFotoPorDefecto(): 'habitacion' en
// OpcionHotel (como siempre) y 'otra' en Proveedor.
trait FotosConTipo
{
    public const TIPOS_FOTO = ['fachada', 'habitacion', 'otra'];

    abstract protected static function tipoFotoPorDefecto(): string;

    // Defensivo — una entrada vieja (string suelto, de antes de la migración de datos)
    // no debe romper el resolve.
    public static function normalizarFotos(array $fotos): array
    {
        $defecto = static::tipoFotoPorDefecto();

        return array_map(
            fn ($f) => is_array($f) ? $f : ['path' => $f, 'tipo_foto' => $defecto],
            $fotos
        );
    }

    public static function fotosResueltas(array $fotos): array
    {
        $defecto = static::tipoFotoPorDefecto();

        return array_map(
            fn (array $f) => ['path' => $f['path'], 'tipo_foto' => $f['tipo_foto'] ?? $defecto, 'url' => StorageUrl::resolve($f['path'])],
            self::normalizarFotos($fotos)
        );
    }
}
