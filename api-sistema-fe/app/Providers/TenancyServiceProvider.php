<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\NamespaceSpatiePermissionCache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

class TenancyServiceProvider extends ServiceProvider
{
    public static string $controllerNamespace = '';

    public function events()
    {
        return [
            Events\CreatingTenant::class => [],
            Events\TenantCreated::class => [
                JobPipeline::make([
                    Jobs\CreateDatabase::class,
                    Jobs\MigrateDatabase::class,
                    // Jobs\SeedDatabase::class,
                    // NO incluido a propósito: PermissionsDemoSeeder tiene un usuario
                    // admin hardcodeado y usa Role::create()/Permission::create() sin
                    // firstOrCreate() (no idempotente) — no está listo para
                    // tenants:seed todavía (ver plan §6 🟢). Se activa cuando eso
                    // se resuelva, no antes.
                ])->send(function (Events\TenantCreated $event) {
                    return $event->tenant;
                })->shouldBeQueued(false),
                // false a propósito: QUEUE_CONNECTION=database en este entorno y no
                // hay worker corriendo — con shouldBeQueued(true) los jobs quedarían
                // encolados sin ejecutarse nunca. Reevaluar cuando haya un worker
                // real en producción.
            ],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            Events\TenantDeleted::class => [],
            // Sin Jobs\DeleteDatabase::class a propósito: el plan (§11.2) define el
            // ciclo de vida del tenant como "archivado", no borrado — un borrado
            // físico automático al hacer Tenant::delete() contradice esa política.
            // Si en el futuro se implementa un borrado real post-retención, que sea
            // un comando explícito, no un side-effect silencioso de este evento.

            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],

            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],

            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
                [NamespaceSpatiePermissionCache::class, 'bootstrap'],
                // Namespacea la cache key de Spatie Permission por tenant — sin esto,
                // dos tenants con un rol del mismo nombre pero permisos distintos
                // podrían leer el caché uno del otro (CACHE_STORE=database no
                // soporta tags, por eso no se usa CacheTenancyBootstrapper acá).
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
                [NamespaceSpatiePermissionCache::class, 'revert'],
            ],

            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],

            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    public function register()
    {
        //
    }

    public function boot()
    {
        $this->bootEvents();
        $this->mapRoutes();

        $this->makeTenancyMiddlewareHighestPriority();

        $this->handleTenantResolutionFailures();
    }

    /**
     * Sin esto, un hostname que no resuelve a ningún tenant (subdominio no
     * registrado, o dominio que ni siquiera matchea el patrón de subdominio)
     * revienta con el 404 HTML default de Laravel — inconsistente con el resto
     * de la API, que responde JSON.
     */
    protected function handleTenantResolutionFailures()
    {
        $onFail = function ($e, $request, $next) {
            return response()->json(['message' => 'Negocio no encontrado'], 404);
        };

        Middleware\InitializeTenancyBySubdomain::$onFail = $onFail;
    }

    protected function bootEvents()
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    protected function mapRoutes()
    {
        $this->app->booted(function () {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::namespace(static::$controllerNamespace)
                    ->group(base_path('routes/tenant.php'));
            }
        });
    }

    protected function makeTenancyMiddlewareHighestPriority()
    {
        $tenancyMiddleware = [
            Middleware\PreventAccessFromCentralDomains::class,
            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app[\Illuminate\Contracts\Http\Kernel::class]->prependToMiddlewarePriority($middleware);
        }
    }
}
