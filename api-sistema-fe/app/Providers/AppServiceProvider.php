<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function ($user, $ability) {
            return $user->hasRole('Super-Admin') ? true : null;
        });

        // Fase 1a (plan-modulo-menus-y-roles.md §4.3/§9.6/§9.7) —
        // App\Listeners\RolePermissionChangedListener reacciona a
        // PermissionAttached/PermissionDetached/RoleAttached/RoleDetached
        // (habilitados recién en esta fase, ver
        // config('permission.events_enabled')). SIN Event::listen() acá a
        // propósito: confirmado con `php artisan event:list` que Laravel ya
        // auto-descubre esos 4 métodos (cada uno con un único parámetro
        // tipado como la clase del evento — la convención exacta de
        // auto-discovery) — registrarlos también acá los duplicaba (2
        // listeners por evento, 2 filas de role_audit_logs por cada
        // permission/role attach/detach real). Verificado con
        // `php artisan event:list` que queda en 1 listener por evento tras
        // sacar el registro explícito.
    }
}
