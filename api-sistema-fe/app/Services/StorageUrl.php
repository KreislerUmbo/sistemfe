<?php

namespace App\Services;

// Arma URLs de archivos servidos por tenant (avatar, imagen, foto) usando el
// helper tenant_asset() del propio paquete stancl/tenancy (ruta
// /tenancy/assets/{path}, ver
// TenancyServiceProvider::configureTenantAssetsMiddleware()) — NUNCA
// env('APP_URL') ni una concatenación manual de host+"/storage/"+path.
//
// Por qué no basta con "/storage/": public/storage es un symlink ESTÁTICO
// (no tenant-aware) que Apache sirve directo, sin pasar por Laravel —
// siempre apunta a storage/app/public (la carpeta CENTRAL), nunca a
// storage/tenant{slug}/app/public/... (donde vive de verdad el archivo de
// cualquier tenant creado después del split a multi-tenancy). Confirmado con
// evidencia real: "umbo" (tenant original, pre-multitenant) tiene sus
// archivos duplicados a mano en ambas carpetas, por eso ahí "funcionaba" —
// pero para cualquier tenant nuevo (ej. "agencia-demo"), /storage/... da 403
// sin importar qué host tenga la URL. tenant_asset() sí resuelve bien porque
// la ruta que genera corre DENTRO de una petición Laravel bootstrapeada,
// donde FilesystemTenancyBootstrapper ya reescribió storage_path() al
// tenant correcto (ver TenantAssetsController::asset() en el paquete).
class StorageUrl
{
    public static function resolve(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return tenant_asset(ltrim($path, '/'));
    }

    public static function resolveMuchas(array $paths): array
    {
        return array_map(fn ($p) => self::resolve($p), $paths);
    }

    // Inverso de resolve(): recupera el path relativo (el que vive en BD) a
    // partir de una URL ya resuelta. Idempotente con un path relativo crudo
    // — necesario donde el frontend recibe fotos[] ya resueltas y las
    // reenvía tal cual al eliminar/reordenar (ver DestinoAtractivoController).
    public static function relativo(string $urlOPath): string
    {
        $marcador = '/tenancy/assets/';
        $pos = strpos($urlOPath, $marcador);

        if ($pos === false) {
            return ltrim($urlOPath, '/');
        }

        return substr($urlOPath, $pos + strlen($marcador));
    }
}
