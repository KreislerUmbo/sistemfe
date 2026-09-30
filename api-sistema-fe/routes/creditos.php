<?php

// Módulo Créditos — Fase 3 (docs/planning/creditos/03-api.md). Cargado desde
// routes/api.php (hereda el prefijo "api"). Mismo pipeline de tenancy que el grupo
// protegido principal. Los ids van como enteros (whereNumber) y se resuelven en el
// controller: el binding implícito correría antes de inicializar el tenant.
// Las rutas fijas (preview, migrar, cobranza-del-dia, configuracion, feriados) van
// antes de creditos/{credito}.

use App\Http\Controllers\Creditos\ClienteCreditoController;
use App\Http\Controllers\Creditos\CobranzaDelDiaController;
use App\Http\Controllers\Creditos\CreditoCicloController;
use App\Http\Controllers\Creditos\CreditoCondonacionController;
use App\Http\Controllers\Creditos\CreditoConfiguracionController;
use App\Http\Controllers\Creditos\CreditoController;
use App\Http\Controllers\Creditos\CreditoLiquidacionController;
use App\Http\Controllers\Creditos\CreditoMigracionController;
use App\Http\Controllers\Creditos\CreditoPagoController;
use App\Http\Controllers\Creditos\CreditoReprogramacionController;
use App\Http\Controllers\Creditos\CreditoRenovacionController;
use App\Http\Controllers\Creditos\FeriadoController;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => ['tenant', 'tenant.active', 'tenant.subscription', 'tenant.token', 'auth:api'],
], function () {
    Route::get('creditos', [CreditoController::class, 'index'])->middleware('permission:creditos.ver');
    Route::post('creditos/preview', [CreditoController::class, 'preview'])->middleware('permission:creditos.crear');
    Route::post('creditos', [CreditoController::class, 'store'])->middleware('permission:creditos.crear');
    Route::post('creditos/migrar', [CreditoMigracionController::class, 'store'])->middleware('permission:creditos.migrar');
    Route::get('creditos/cobranza-del-dia', [CobranzaDelDiaController::class, 'index'])->middleware('permission:creditos.cobrar');

    Route::get('creditos/configuracion', [CreditoConfiguracionController::class, 'show'])->middleware('permission:creditos.configurar');
    Route::put('creditos/configuracion', [CreditoConfiguracionController::class, 'update'])->middleware('permission:creditos.configurar');
    Route::get('creditos/feriados', [FeriadoController::class, 'index'])->middleware('permission:creditos.configurar');
    Route::post('creditos/feriados', [FeriadoController::class, 'store'])->middleware('permission:creditos.configurar');
    Route::put('creditos/feriados/{feriado}', [FeriadoController::class, 'update'])->whereNumber('feriado')->middleware('permission:creditos.configurar');
    Route::delete('creditos/feriados/{feriado}', [FeriadoController::class, 'destroy'])->whereNumber('feriado')->middleware('permission:creditos.configurar');

    Route::prefix('creditos/{credito}')->whereNumber('credito')->group(function () {
        Route::get('/', [CreditoController::class, 'show'])->middleware('permission:creditos.ver');
        Route::put('/', [CreditoController::class, 'update'])->middleware('permission:creditos.crear');
        Route::get('estado-cuenta', [CreditoController::class, 'estadoCuenta'])->middleware('permission:creditos.ver');

        Route::post('activar', [CreditoCicloController::class, 'activar'])->middleware('permission:creditos.crear');
        Route::post('corregir', [CreditoCicloController::class, 'corregir'])->middleware('permission:creditos.corregir');
        Route::post('anular', [CreditoCicloController::class, 'anular'])->middleware('permission:creditos.corregir');
        Route::post('castigar', [CreditoCicloController::class, 'castigar'])->middleware('permission:creditos.castigar');
        Route::post('revertir-castigo', [CreditoCicloController::class, 'revertirCastigo'])->middleware('permission:creditos.castigar');
        Route::post('autorizaciones', [CreditoCicloController::class, 'autorizar'])->middleware('permission:creditos.autorizar_excepcion');

        Route::post('pagos/cotizar', [CreditoPagoController::class, 'cotizar'])->middleware('permission:creditos.cobrar');
        // Retroactivo: mismo endpoint; creditos.pago_fecha_anterior lo verifica el servicio.
        Route::post('pagos', [CreditoPagoController::class, 'store'])->middleware('permission:creditos.cobrar');
        Route::patch('pagos/{pago}', [CreditoPagoController::class, 'update'])->whereNumber('pago')->middleware('permission:creditos.cobrar');
        // Propio con su caja abierta; otros casos exigen creditos.anular_pago en el servicio (00 1.8).
        Route::post('pagos/{pago}/anular', [CreditoPagoController::class, 'anular'])->whereNumber('pago')->middleware('permission:creditos.cobrar');

        Route::get('liquidacion', [CreditoLiquidacionController::class, 'cotizar'])->middleware('permission:creditos.cobrar');
        Route::post('liquidar', [CreditoLiquidacionController::class, 'liquidar'])->middleware('permission:creditos.cobrar');

        Route::post('reprogramar/preview', [CreditoReprogramacionController::class, 'preview'])->middleware('permission:creditos.reprogramar');
        Route::post('reprogramar', [CreditoReprogramacionController::class, 'store'])->middleware('permission:creditos.reprogramar');
        Route::post('condonar-mora', [CreditoCondonacionController::class, 'store'])->middleware('permission:creditos.condonar_mora');
        Route::post('renovar/preview', [CreditoRenovacionController::class, 'preview'])->middleware('permission:creditos.crear');
        Route::post('renovar', [CreditoRenovacionController::class, 'store'])->middleware('permission:creditos.crear');
    });

    Route::prefix('clientes/{cliente}')->whereNumber('cliente')->group(function () {
        Route::get('resumen-credito', [ClienteCreditoController::class, 'resumen'])->middleware('permission:creditos.crear');
        Route::get('ficha-credito', [ClienteCreditoController::class, 'ficha'])->middleware('permission:creditos.ver');
        Route::put('ficha-credito', [ClienteCreditoController::class, 'guardarFicha'])->middleware('permission:creditos.crear');
        Route::post('ficha-credito/archivos', [ClienteCreditoController::class, 'subirArchivo'])->middleware('permission:creditos.crear');
        Route::get('ficha-credito/archivos/{archivo}', [ClienteCreditoController::class, 'verArchivo'])->whereNumber('archivo')->middleware('permission:creditos.ver');
        Route::put('cobrador', [ClienteCreditoController::class, 'asignarCobrador'])->middleware('permission:creditos.cartera.asignar');
    });
});
