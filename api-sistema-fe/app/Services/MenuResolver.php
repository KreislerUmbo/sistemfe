<?php

namespace App\Services;

use App\Models\Central\MenuItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Fase 1a (plan-modulo-menus-y-roles.md §4.1/§4.3) — único punto de verdad
// para resolver el árbol de navegación de un usuario. Cacheado por
// tenant_id + user_id (nunca solo por rol — Spatie permite permisos
// directos al usuario además del rol, el mismo caso que el bug de §1 del
// plan pisaba).
class MenuResolver
{
    // TTL como red de seguridad, no como mecanismo principal — la
    // invalidación explícita (RoleAuditListener, ver §4.3/§9.7) es la
    // defensa primaria. 24h porque un cambio de permiso que tarde hasta un
    // día en reflejarse SI la invalidación explícita fallara por algún
    // motivo es un riesgo aceptable (no hay ningún caso de seguridad real
    // detrás del menú en sí — el gate de verdad es el middleware
    // permission: de cada ruta, §9.1).
    private const CACHE_TTL_SECONDS = 60 * 60 * 24;

    public function paraUsuario(User $user, Tenant $tenant): array
    {
        return Cache::remember(
            $this->claveUsuario((string) $tenant->id, $user->id),
            self::CACHE_TTL_SECONDS,
            fn () => $this->resolver($user, $tenant)
        );
    }

    private function resolver(User $user, Tenant $tenant): array
    {
        $items = MenuItem::activos()
            ->where(function ($q) use ($tenant) {
                $q->whereNull('giro')->orWhere('giro', $tenant->giro);
            })
            ->get();

        // Paso 2 del plan (§4.1) — filtro por modulos_efectivos($tenant) — NO
        // IMPLEMENTADO A PROPÓSITO. Confirmado con grep sobre todo app/ antes
        // de escribir esta clase: ni modulos_efectivos(), ni
        // tenant_modulo_overrides, ni un modelo Modulo existen todavía en el
        // código. plan-modulo-planes-acceso.md §3.4/3.5 los marca
        // explícitamente como "trabajo genuinamente nuevo, sin conflicto",
        // no como ya construido — no corresponde a este brief improvisar un
        // reemplazo. `menu_items.modulo_id` ya existe en el schema (nullable,
        // sin FK real) para cuando ese módulo exista: el único cambio que
        // haría falta acá es agregar un ->filter() más en este punto exacto.
        // Hoy ningún menu_item sembrado tiene modulo_id, así que el paso no
        // filtra nada de todas formas.

        $visibles = $items->filter(
            fn (MenuItem $item) => $item->permiso_requerido === null || $user->can($item->permiso_requerido)
        );

        $arbol = $this->armarArbol($visibles, null);

        return $this->podarGruposVacios($arbol);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MenuItem>  $items
     */
    private function armarArbol($items, ?int $parentId): array
    {
        return $items
            ->filter(fn (MenuItem $i) => $i->parent_id === $parentId)
            ->sortBy('orden')
            ->map(fn (MenuItem $item) => [
                'codigo' => $item->codigo,
                'label' => $item->label,
                'icono' => $item->icono,
                'ruta' => $item->ruta,
                'tipo' => $item->tipo, // interno, se quita en podarGruposVacios()
                'hijos' => $this->armarArbol($items, $item->id),
            ])
            ->values()
            ->all();
    }

    private function podarGruposVacios(array $nodos): array
    {
        $resultado = [];
        foreach ($nodos as $nodo) {
            $nodo['hijos'] = $this->podarGruposVacios($nodo['hijos']);

            if ($nodo['tipo'] === 'grupo' && empty($nodo['hijos'])) {
                continue;
            }

            unset($nodo['tipo']);
            $resultado[] = $nodo;
        }

        return $resultado;
    }

    private function claveUsuario(string $tenantId, int $userId): string
    {
        return "menu:{$tenantId}:{$userId}";
    }

    public function invalidarUsuario(string $tenantId, int $userId): void
    {
        Cache::forget($this->claveUsuario($tenantId, $userId));
    }

    /**
     * Limpia TODAS las claves de menú del tenant ACTUALMENTE activo — usar
     * solo dentro de una request/job con tenancy ya inicializada (ej. el
     * listener que reacciona a un cambio de permiso de un ROL, que afecta a
     * varios usuarios a la vez y sería más frágil enumerar uno por uno).
     *
     * No usa Cache::tags(): CACHE_STORE=database no las soporta (ver
     * config/tenancy.php, comentario sobre por qué CacheTenancyBootstrapper
     * no está activo). En cambio, borra por prefijo directo contra la tabla
     * 'cache' — DatabaseTenancyBootstrapper ya aísla esa tabla por tenant
     * (es tabla de tenant, no central), así que este borrado SOLO puede
     * afectar al tenant activo en este momento, nunca a otro.
     */
    public function invalidarTenantActivo(): void
    {
        if (! tenancy()->initialized) {
            Log::warning('MenuResolver::invalidarTenantActivo() llamado sin tenancy inicializada — no-op para no arriesgar borrar la tabla equivocada.');

            return;
        }

        $store = Cache::getStore();
        if (! $store instanceof DatabaseStore) {
            // Entorno de test u otro store sin tabla propia — el TTL sigue
            // siendo la red de seguridad, no rompe nada dejarlo sin efecto.
            return;
        }

        DB::table(config('cache.stores.database.table', 'cache'))
            ->where('key', 'like', $store->getPrefix().'menu:%')
            ->delete();
    }
}
