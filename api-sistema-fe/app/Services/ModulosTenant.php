<?php

namespace App\Services;

use App\Models\Central\Modulo;
use App\Models\Central\TenantModuloOverride;
use App\Models\Tenant;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * T-135 (D1) — módulos efectivos de un tenant: los overrides con habilitado = true. Los módulos
 * del plan (plan_modulo) no entran todavía. Único punto de lectura para el menú y el middleware
 * `modulo:`.
 */
class ModulosTenant
{
    // La invalidación explícita de cambiar() es la defensa primaria; el TTL, la red de seguridad.
    private const CACHE_TTL_SECONDS = 60 * 60 * 24;

    /** @return list<string> códigos de los módulos activos */
    public function efectivos(Tenant $tenant): array
    {
        return Cache::remember(
            $this->clave((string) $tenant->id),
            self::CACHE_TTL_SECONDS,
            fn () => Modulo::query()
                ->whereIn('id', TenantModuloOverride::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('habilitado', true)
                    ->select('modulo_id'))
                ->orderBy('codigo')
                ->pluck('codigo')
                ->all()
        );
    }

    public function activo(Tenant $tenant, string $codigo): bool
    {
        return in_array($codigo, $this->efectivos($tenant), true);
    }

    public function cambiar(Tenant $tenant, string $codigo, bool $habilitado, ?string $notas = null): void
    {
        $modulo = Modulo::where('codigo', $codigo)->firstOrFail();

        TenantModuloOverride::updateOrCreate(
            ['tenant_id' => $tenant->id, 'modulo_id' => $modulo->id],
            ['habilitado' => $habilitado] + ($notas === null ? [] : ['notas' => $notas])
        );

        // La caché (de módulos y de menú) vive en la base del tenant: se limpia desde adentro.
        $tenant->run(function () use ($tenant) {
            $this->olvidar((string) $tenant->id);
            app(MenuResolver::class)->invalidarTenantActivo();
        });
    }

    // Con el store 'database' se borra además la fila directamente: en una petición central el
    // store puede haber quedado atado a la conexión central (mismo criterio que MenuResolver).
    private function olvidar(string $tenantId): void
    {
        Cache::forget($this->clave($tenantId));

        $store = Cache::getStore();
        if ($store instanceof DatabaseStore) {
            DB::table(config('cache.stores.database.table', 'cache'))
                ->where('key', $store->getPrefix().$this->clave($tenantId))
                ->delete();
        }
    }

    private function clave(string $tenantId): string
    {
        return "modulos:{$tenantId}";
    }
}
