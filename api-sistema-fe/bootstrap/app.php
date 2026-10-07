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
            // Fase 0c (plan-modulo-menus-y-roles.md §9.1, modo "sombra") — nunca
            // bloquea, solo registra en permission_shadow_logs. Ver
            // ShadowPermissionMiddleware.
            'shadow.permission' => \App\Http\Middleware\ShadowPermissionMiddleware::class,
            // Multi-tenancy — wireados en routes/api.php, orden: tenant → tenant.active
            // → tenant.subscription → tenant.token → auth:*.
            'tenant' => \Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain::class,
            'tenant.active' => \App\Http\Middleware\EnsureTenantIsActive::class,
            // Fase B.2.2 (plan-panel-superadmin.md) — suspensión por falta de pago,
            // concepto distinto de 'archivado' (EnsureTenantIsActive), mismo patrón.
            'tenant.subscription' => \App\Http\Middleware\TenantSubscriptionMiddleware::class,
            'tenant.token' => \App\Http\Middleware\EnsureTokenBelongsToTenant::class,
            // Escrituras del catálogo central (Portal web, recursos): solo el tenant
            // dueño de la plataforma, ver config/plataforma.php.
            'tenant.plataforma' => \App\Http\Middleware\EnsureTenantIsPlataforma::class,
            // Panel superadmin — usar junto a auth:central en rutas /api/central/*
            // (Fase A), orden: auth:central → central.token.
            'central.token' => \App\Http\Middleware\EnsureTokenIsCentralGuard::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Gate de permisos (Fase 0b/0c): el mensaje por defecto de Spatie viene
        // en inglés y sin decir qué falta — las pantallas muestran
        // error.response.data.message tal cual, así que se responde en español
        // con el permiso requerido para que el administrador sepa qué asignar.
        $exceptions->render(function (\Spatie\Permission\Exceptions\UnauthorizedException $e, $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }
            $requeridos = $e->getRequiredPermissions();

            return response()->json([
                'message' => 'No tienes permiso para realizar esta acción.'
                    . ($requeridos ? ' Permiso requerido: ' . implode(' o ', $requeridos) . '. Pide a un administrador que lo asigne a tu rol.' : ''),
                'permisos_requeridos' => $requeridos,
            ], 403);
        });

        // Módulo Créditos (00 §4): las reglas del motor rechazan con mensajes de negocio en
        // español y sin datos internos; se devuelven como 422 para que la pantalla los muestre.
        $exceptions->render(function (\App\Services\Creditos\Motor\Excepciones\PagoExcedeDeuda $e) {
            return response()->json([
                'message' => 'El pago supera lo que se debe liquidando hoy. Usa "Liquidar" para cancelar el crédito.',
                'monto_liquidacion' => \App\Services\Creditos\Dinero::aSoles($e->montoLiquidacion),
            ], 422);
        });
        $exceptions->render(function (\App\Services\Creditos\LimitesExcedidos $e) {
            return response()->json(['message' => $e->getMessage(), 'bloqueos' => $e->detalle()], 422);
        });
        $exceptions->render(function (\App\Services\Creditos\Motor\Excepciones\CondicionesInvalidas|\App\Services\Creditos\Motor\Excepciones\MetodoNoSoportado $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
