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
use App\Http\Controllers\Creditos\CreditoDocumentoController;
use App\Http\Controllers\Creditos\CreditoPlantillaController;
use App\Http\Controllers\Creditos\CreditoConfiguracionController;
use App\Http\Controllers\Creditos\CreditoController;
use App\Http\Controllers\Creditos\CreditoLiquidacionController;
use App\Http\Controllers\Creditos\CreditoMigracionController;
use App\Http\Controllers\Creditos\CreditoPagoController;
use App\Http\Controllers\Creditos\CreditoReprogramacionController;
use App\Http\Controllers\Creditos\CreditoRenovacionController;
use App\Http\Controllers\Creditos\FeriadoController;
use App\Http\Controllers\Creditos\ReporteCreditoController;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => ['tenant', 'tenant.active', 'tenant.subscription', 'tenant.token', 'auth:api', 'user.active'],
], function () {
    Route::get('creditos', [CreditoController::class, 'index'])->middleware('permission:creditos.ver');
    // Migrar también usa el preview (cronograma con fechas pasadas).
    Route::post('creditos/preview', [CreditoController::class, 'preview'])->middleware('permission:creditos.crear|creditos.migrar');
    Route::post('creditos', [CreditoController::class, 'store'])->middleware('permission:creditos.crear');
    Route::post('creditos/migrar', [CreditoMigracionController::class, 'store'])->middleware('permission:creditos.migrar');
    // Fase 4d: panel y reportes. creditos.ver aquí; el controller exige creditos.reportes (salvo la
    // agenda, que usa también el cobrador) y creditos.ver_todos en los reportes financieros y de control.
    Route::get('creditos/panel', [ReporteCreditoController::class, 'panel'])->middleware('permission:creditos.ver');
    Route::get('creditos/reportes/{reporte}', [ReporteCreditoController::class, 'show'])->whereIn('reporte', ReporteCreditoController::REPORTES)->middleware('permission:creditos.ver');
    Route::get('creditos/reportes/{reporte}/pdf-url', [ReporteCreditoController::class, 'pdfUrl'])->whereIn('reporte', ReporteCreditoController::REPORTES)->middleware('permission:creditos.ver');
    Route::get('creditos/reportes/{reporte}/excel', [ReporteCreditoController::class, 'excel'])->whereIn('reporte', ReporteCreditoController::REPORTES)->middleware('permission:creditos.ver');
    Route::get('creditos/cobranza-del-dia', [CobranzaDelDiaController::class, 'index'])->middleware('permission:creditos.cobrar');
    Route::get('creditos/cartera/usuarios', [ClienteCreditoController::class, 'usuariosCartera'])->middleware('permission:creditos.cartera.asignar');
    // 04c.1: traspasar toda la cartera de un usuario (se va, cambia de zona) a otro.
    Route::get('creditos/cartera/titulares', [ClienteCreditoController::class, 'titularesCartera'])->middleware('permission:creditos.cartera.asignar');
    Route::post('creditos/cartera/traspasar', [ClienteCreditoController::class, 'traspasarCartera'])->middleware('permission:creditos.cartera.asignar');

    // Lectura también con crear/migrar: esos formularios muestran estos defaults.
    Route::get('creditos/configuracion', [CreditoConfiguracionController::class, 'show'])->middleware('permission:creditos.configurar|creditos.crear|creditos.migrar');
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

        // Documentos (Fase 4b): devuelven una URL firmada; el PDF se sirve por las rutas de abajo.
        Route::get('documentos', [CreditoDocumentoController::class, 'index'])->middleware('permission:creditos.ver');
        Route::get('documentos/url', [CreditoDocumentoController::class, 'url'])->middleware('permission:creditos.ver');
        Route::get('documentos/{documento}/url', [CreditoDocumentoController::class, 'archivoUrl'])->whereNumber('documento')->middleware('permission:creditos.ver');
        Route::post('documentos/contrato-firmado', [CreditoDocumentoController::class, 'subirFirmado'])->middleware('permission:creditos.crear');
        Route::get('pagos/{pago}/recibo-url', [CreditoDocumentoController::class, 'reciboUrl'])->whereNumber('pago')->middleware('permission:creditos.ver');
    });

    Route::get('creditos/plantillas/contrato', [CreditoPlantillaController::class, 'show'])->middleware('permission:creditos.configurar');
    Route::put('creditos/plantillas/contrato', [CreditoPlantillaController::class, 'update'])->middleware('permission:creditos.configurar');
    Route::post('creditos/plantillas/contrato/vista-previa', [CreditoPlantillaController::class, 'vistaPrevia'])->middleware('permission:creditos.configurar');

    Route::prefix('clientes/{cliente}')->whereNumber('cliente')->group(function () {
        Route::get('resumen-credito', [ClienteCreditoController::class, 'resumen'])->middleware('permission:creditos.crear|creditos.ver');
        Route::get('ficha-credito', [ClienteCreditoController::class, 'ficha'])->middleware('permission:creditos.ver');
        Route::put('ficha-credito', [ClienteCreditoController::class, 'guardarFicha'])->middleware('permission:creditos.crear');
        Route::post('ficha-credito/archivos', [ClienteCreditoController::class, 'subirArchivo'])->middleware('permission:creditos.crear');
        Route::get('ficha-credito/archivos/{archivo}', [ClienteCreditoController::class, 'verArchivo'])->whereNumber('archivo')->middleware('permission:creditos.ver');
        Route::put('cartera', [ClienteCreditoController::class, 'asignarCartera'])->middleware('permission:creditos.cartera.asignar');
        Route::put('limites-credito', [ClienteCreditoController::class, 'guardarLimites'])->middleware('permission:creditos.configurar');
        // 04c.1: saldo a favor del cliente — ver y devolver (sale de la caja de quien devuelve).
        Route::get('saldo-a-favor', [ClienteCreditoController::class, 'saldoAFavor'])->middleware('permission:creditos.ver');
        Route::post('saldo-a-favor/devolver', [ClienteCreditoController::class, 'devolverSaldo'])->middleware('permission:creditos.cobrar');
    });
});

// PDFs y archivos del crédito: solo por URL firmada temporal (mismo patrón que sales-pdf).
// La firma incluye el usuario (?u=) que pidió el documento; el servicio revalida su cartera.
Route::middleware(['tenant', 'tenant.active', 'tenant.token', 'signed'])->group(function () {
    Route::get('creditos-pdf/{credito}/{documento}', [CreditoDocumentoController::class, 'pdf'])
        ->whereNumber('credito')->whereIn('documento', CreditoDocumentoController::DOCUMENTOS)->name('creditos.pdf');
    Route::get('creditos-recibo-pdf/{pago}', [CreditoDocumentoController::class, 'recibo'])->whereNumber('pago')->name('creditos.recibo.pdf');
    Route::get('creditos-reporte-pdf/{reporte}', [ReporteCreditoController::class, 'pdf'])
        ->whereIn('reporte', ReporteCreditoController::REPORTES)->name('creditos.reporte.pdf');
    Route::get('creditos-archivo/{documento}', [CreditoDocumentoController::class, 'archivo'])->whereNumber('documento')->name('creditos.archivo');
});
