<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            // Multi-tenancy — wireados en routes/api.php, orden: tenant → tenant.active
            // → tenant.subscription → tenant.token → auth:*.
            'tenant' => \Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain::class,
            'tenant.active' => \App\Http\Middleware\EnsureTenantIsActive::class,
            // Fase B.2.2 (plan-panel-superadmin.md) — suspensión por falta de pago,
            // concepto distinto de 'archivado' (EnsureTenantIsActive), mismo patrón.
            'tenant.subscription' => \App\Http\Middleware\TenantSubscriptionMiddleware::class,
            'tenant.token' => \App\Http\Middleware\EnsureTokenBelongsToTenant::class,
            // Panel superadmin — usar junto a auth:central en rutas /api/central/*
            // (Fase A), orden: auth:central → central.token.
            'central.token' => \App\Http\Middleware\EnsureTokenIsCentralGuard::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
