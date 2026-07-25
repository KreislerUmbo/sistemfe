<?php

namespace App\Listeners;

use Spatie\Permission\PermissionRegistrar;

/**
 * Spatie cachea roles/permisos con una key fija (config('permission.cache.key')),
 * sin namespace por tenant. Con CACHE_STORE=database (no soporta tags — ver
 * TenancyServiceProvider, motivo de por qué CacheTenancyBootstrapper de stancl no se
 * usa acá), dos tenants con un rol del mismo nombre pero permisos distintos podrían
 * leer el caché uno del otro. Namespacing manual de la key, no de tags.
 *
 * Además: Illuminate\Cache\CacheManager::store() memoiza la instancia de store
 * resuelta ($this->stores[$name] ??= ...) — igual que Storage::forgetDisk() hace
 * falta para el disco, acá hace falta forgetDriver() para el store 'database': sin
 * esto, si algo resuelve el store ANTES de que tenancy cambie la conexión activa
 * (ej. un worker de cola procesando tenants en secuencia dentro del mismo proceso),
 * el store queda pegado a la conexión de ese primer momento para el resto del
 * proceso — no es un problema exclusivo de Spatie, afecta cualquier uso de Cache::.
 *
 * Tercer nivel encontrado al verificar con 2 tenants reales (no se veía con 1 solo):
 * PermissionRegistrar::loadPermissions() tiene un fast-path EN MEMORIA
 * (if ($this->permissions) return;) que ni siquiera llega a tocar el cache store en
 * la segunda llamada dentro del mismo proceso — initializeCache() no lo resetea. Sin
 * limpiar esto, el tenant B heredaría en memoria los permisos ya cargados del tenant A
 * dentro del mismo proceso (tinker, worker de cola, tenants:migrate/tenants:seed
 * batch) — no se manifiesta en una request HTTP normal (proceso fresco por request),
 * pero sí en cualquier proceso de larga vida que procese más de un tenant.
 *
 * OJO — se resetea SOLO la propiedad en memoria ($permissions, vía closure bindeado,
 * porque es protected), NO se usa forgetCachedPermissions(): ese método también borra
 * la fila persistida del cache store. Si se llamara en cada TenancyInitialized (o sea,
 * en cada request), el cache de Spatie nunca llegaría a servir de nada entre requests
 * — se recalcularía desde roles/permissions/role_has_permissions siempre, anulando el
 * propósito del cache persistente para todos los tenants, no solo al cambiar de uno a
 * otro.
 */
class NamespaceSpatiePermissionCache
{
    public const DEFAULT_KEY = 'spatie.permission.cache';

    public function bootstrap(): void
    {
        app('cache')->forgetDriver();

        config(['permission.cache.key' => static::DEFAULT_KEY . '.tenant_' . tenant('id')]);

        app(PermissionRegistrar::class)->initializeCache();
        $this->resetInMemoryPermissions();
    }

    public function revert(): void
    {
        app('cache')->forgetDriver();

        config(['permission.cache.key' => static::DEFAULT_KEY]);

        app(PermissionRegistrar::class)->initializeCache();
        $this->resetInMemoryPermissions();
    }

    protected function resetInMemoryPermissions(): void
    {
        $registrar = app(PermissionRegistrar::class);

        \Closure::bind(function () {
            $this->permissions = null;
        }, $registrar, PermissionRegistrar::class)();
    }
}
