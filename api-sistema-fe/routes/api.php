<?php

use App\Http\Controllers\Advance\AdvanceController;
use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\AlternativaDestinoController;
use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\AmenidadController;
use App\Http\Controllers\AgenciaViajes\BibliotecaCotizadorController;
use App\Http\Controllers\AgenciaViajes\CondicionesGeneralesController;
use App\Http\Controllers\AgenciaViajes\ConfiguracionAgenciaController;
use App\Http\Controllers\AgenciaViajes\ConfiguracionCodigosController;
use App\Http\Controllers\AgenciaViajes\ContenidoTourController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\AgenciaViajes\CuentaBancariaController;
use App\Http\Controllers\AgenciaViajes\DestinoAtractivoController;
use App\Http\Controllers\AgenciaViajes\DestinoServicioController;
use App\Http\Controllers\AgenciaViajes\GuiaController;
use App\Http\Controllers\AgenciaViajes\GuiaTarifaController;
use App\Http\Controllers\AgenciaViajes\OpcionMayoristaController;
use App\Http\Controllers\AgenciaViajes\PaquetePlantillaController;
use App\Http\Controllers\AgenciaViajes\PaquetePlantillaItemController;
use App\Http\Controllers\AgenciaViajes\ProveedorController;
use App\Http\Controllers\AgenciaViajes\ProveedorServicioController;
use App\Http\Controllers\AgenciaViajes\ProveedorTarifaController;
use App\Http\Controllers\AgenciaViajes\ProveedorTipoConfigController;
use App\Http\Controllers\AgenciaViajes\ReservaAnticipoController;
use App\Http\Controllers\AgenciaViajes\ReporteOperativoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaItemPasajeroController;
use App\Http\Controllers\AgenciaViajes\ReservaPasajeroController;
use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Http\Controllers\AgenciaViajes\ServicioController;
use App\Http\Controllers\AgenciaViajes\TemporadaController;
use App\Http\Controllers\AgenciaViajes\TemporadaOcurrenciaController;
use App\Http\Controllers\AgenciaViajes\TourItinerarioItemController;
use App\Http\Controllers\AgenciaViajes\VentaDirectaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Cash\BranchController;
use App\Http\Controllers\Cash\CashConceptController;
use App\Http\Controllers\Cash\CashMovementController;
use App\Http\Controllers\Cash\CashRegisterController;
use App\Http\Controllers\Cash\CashSessionController;
use App\Http\Controllers\Cash\PaymentMethodController;
use App\Http\Controllers\Cash\SupplierController;
use App\Http\Controllers\Central\CentralAuditLogController;
use App\Http\Controllers\Central\CentralAuthController;
use App\Http\Controllers\Central\ProveedorTipoController;
use App\Http\Controllers\Central\TenantAdminController;
use App\Http\Controllers\Central\TenantBackupController;
use App\Http\Controllers\Central\TenantPlanController;
use App\Http\Controllers\Central\TenantRestoreController;
use App\Http\Controllers\Central\TenantSubscriptionController;
use App\Http\Controllers\Central\TenantSunatController;
use App\Http\Controllers\Client\ClientController;
use App\Http\Controllers\Client\CompanyController;
use App\Http\Controllers\CommercialQuote\CommercialQuoteAnticipoController;
use App\Http\Controllers\CommercialQuote\CommercialQuoteController;
use App\Http\Controllers\Credit\CreditInstallmentController;
use App\Http\Controllers\Credit\CreditPaymentController;
use App\Http\Controllers\Credit\CreditReceivablesController;
use App\Http\Controllers\Credit\PaymentReceiptController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\Portal\Admin\ManualRecursoController;
use App\Http\Controllers\Portal\Admin\SystemCategoryController;
use App\Http\Controllers\Portal\Admin\SystemController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalOrderController;
use App\Http\Controllers\Portal\ProfileController;
use App\Http\Controllers\Product\CategorieController;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Role\RoleController;
use App\Http\Controllers\Sale\FacturacionElectronicaController;
use App\Http\Controllers\Sale\NotaController;
use App\Http\Controllers\Sale\NotaElectronicaController;
use App\Http\Controllers\Sale\SaleController;
use App\Http\Controllers\Sale\SaleDetailController;
use App\Http\Controllers\Sale\SalePaymentController;
use App\Http\Controllers\Sale\SerieComprobanteController;
use App\Http\Controllers\Sale\TipoComprobanteController;
use App\Http\Controllers\User\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

/* 
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
 */

Route::group([
    'prefix' => 'auth',
    'middleware' => ['tenant', 'tenant.active', 'tenant.subscription', 'tenant.token'],
], function ($router) {
    // throttle:6,1 = máx. 6 intentos por minuto por IP+ruta (Laravel RateLimiter por
    // defecto) — antes no había ningún límite, quedaba abierto a fuerza bruta.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1')->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login');
    Route::post('/me', [AuthController::class, 'me'])->middleware('auth:api')->name('me');
    // Renovación silenciosa de sesión (aviso "por expirar" en el frontend,
    // http-client.ts) — el método ya existía en AuthController pero nunca
    // había quedado enrutado. auth:api exige token todavía válido: se llama
    // ANTES de que expire (temporizador en el frontend), no después.
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('auth:api')->name('refresh');
    // Mismo caso que /refresh: el método ya existía (auth('api')->logout(),
    // invalida/blacklistea el token) pero nunca había quedado enrutado —
    // "cerrar sesión" en el frontend solo borraba el token del navegador,
    // el JWT seguía siendo válido en el servidor hasta que vencía solo.
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api')->name('logout');
});

// Logo/nombre comercial del tenant para pantallas sin sesión (login) y para el
// sidebar — mismo grupo de middleware que /auth/login (resuelve el tenant por
// subdominio, EnsureTokenBelongsToTenant deja pasar si no hay token). Público
// a propósito: solo expone branding, nunca datos fiscales (ver branding() en
// CompanyController).
Route::get('branding', [CompanyController::class, 'branding'])
    ->middleware(['tenant', 'tenant.active', 'tenant.subscription', 'tenant.token'])
    ->name('branding');

// Rutas 100% CENTRALES — sin tenant/tenant.token. Deben responder sin necesidad de
// que ningún tenant/subdominio se haya resuelto (catálogo comercial del marketplace,
// compartido por todos los negocios).
Route::group([
    'middleware' => ['auth:api'],
], function ($router) {
    Route::resource('system_categories', SystemCategoryController::class);
    Route::post("system_categories/{id}", [SystemCategoryController::class, 'update']);

    Route::get("systems/config", [SystemController::class, 'config']);
    Route::resource('systems', SystemController::class);

    // Contenido de ayuda del marketplace (tutoriales: cómo registrar producto, venta,
    // etc.) — catálogo de la plataforma, no dato de negocio de un tenant. Antes vivía
    // mal cableado contra ProductController/products (ver plan §1c.3e/§1c.3f).
    Route::resource('recursos', ManualRecursoController::class);
});

// Panel superadmin (plan-panel-superadmin.md, Fase A) — guard 'central', completamente
// separado del guard 'api' de tenants y del bloque "100% CENTRALES" de arriba (ese sigue
// protegido por auth:api, es el catálogo comercial del marketplace consumido por tenants
// logueados). Sin 'tenant'/'tenant.active': el panel vive fuera de la resolución de
// tenancy por diseño.
Route::prefix('central')->group(function () {
    // throttle:6,1 = máx. 6 intentos por minuto por IP — mismo criterio que el login
    // de tenant (arriba) y el del portal (abajo), sin límite previo.
    Route::post('auth/login', [CentralAuthController::class, 'login'])->middleware('throttle:6,1');

    Route::middleware(['auth:central', 'central.token'])->group(function () {
        Route::get('tenants', [TenantAdminController::class, 'index']);
        Route::post('tenants', [TenantAdminController::class, 'store']);
        Route::get('tenants/{id}', [TenantAdminController::class, 'show']);

        // Edición de un tenant ya creado (razón social/giro) — antes solo por
        // SQL/tinker. Cambiar `giro` dispara migrarVertical() del lado del servicio.
        Route::put('tenants/{id}', [TenantAdminController::class, 'update']);

        // Restablecer el password del admin (rol Super-Admin) del tenant — antes solo
        // por tinker. Acción separada de la edición general de arriba a propósito.
        Route::post('tenants/{id}/reset-admin-password', [TenantAdminController::class, 'resetAdminPassword']);

        // Facturación externa por tenant (PEGAR-EN-CLAUDE-CODE-facturacion-externa-
        // tenant.md §1) — endpoint dedicado, separado de update() a propósito (misma
        // razón que reset-admin-password): decisión de modelo de negocio, propia
        // acción de auditoría en central_audit_logs.
        Route::put('tenants/{id}/facturacion-habilitada', [TenantAdminController::class, 'updateFacturacionHabilitada']);

        // "Archivado, no borrado" (§11.2) — bloquea login/API, conserva base/storage.
        // Wrappers HTTP delgados sobre TenantProvisioningService, misma lógica que los
        // comandos CLI tenants:archive/tenants:restore.
        Route::post('tenants/{id}/archive', [TenantAdminController::class, 'archive']);
        Route::post('tenants/{id}/restore', [TenantAdminController::class, 'restore']);

        // Eliminación real — deliberadamente estrecha (solo si el tenant nunca tuvo
        // datos reales, ver TenantProvisioningService::eliminarSiVacio()). Pensado para
        // "me equivoqué al crearlo", nunca como alternativa al archivado.
        Route::delete('tenants/{id}', [TenantAdminController::class, 'destroy']);

        // Fase B.3 — completar Company + SunatConfig (con certificado) de un tenant.
        Route::post('tenants/{id}/company', [TenantSunatController::class, 'company']);
        Route::get('tenants/{id}/company', [TenantSunatController::class, 'companyShow']);
        Route::get('tenants/{id}/sunat-config', [TenantSunatController::class, 'sunatConfigShow']);
        Route::post('tenants/{id}/sunat-config', [TenantSunatController::class, 'sunatConfigStore']);
        Route::post('tenants/{id}/sunat-config/certificado', [TenantSunatController::class, 'sunatConfigCertificado']);

        // Fase E, Paso 2 — botón informativo "probar emisión": confirma que el tenant
        // puede emitir (Company/SunatConfig/certificado) sin quemar ningún correlativo
        // ni enviar nada a SUNAT.
        Route::post('tenants/{id}/test-emission', [TenantSunatController::class, 'testEmission']);

        // Fase B.2.6 — catálogo de planes de suscripción (nombre + precio + límites),
        // hasta ahora sin ningún CRUD real (se poblaba a mano por tinker).
        Route::get('tenant-plans', [TenantPlanController::class, 'index']);
        Route::post('tenant-plans', [TenantPlanController::class, 'store']);
        Route::put('tenant-plans/{id}', [TenantPlanController::class, 'update']);
        Route::delete('tenant-plans/{id}', [TenantPlanController::class, 'destroy']);

        // Catálogo central `proveedor_tipos` (vertical Agencia de Viajes) — antes solo
        // sembrado por ProveedorTipoSeeder, sin ningún CRUD real. destroy() desactiva,
        // no borra (ver ProveedorTipoController).
        Route::get('proveedor-tipos', [ProveedorTipoController::class, 'index']);
        Route::post('proveedor-tipos', [ProveedorTipoController::class, 'store']);
        Route::put('proveedor-tipos/{id}', [ProveedorTipoController::class, 'update']);
        Route::delete('proveedor-tipos/{id}', [ProveedorTipoController::class, 'destroy']);

        // Fase B.2.3 — generación manual de invoice de suscripción.
        Route::post('tenants/{id}/invoices', [TenantSubscriptionController::class, 'generarInvoice']);
        Route::get('tenants/{id}/subscription', [TenantSubscriptionController::class, 'show']);
        // Fase B.2.6 — crear la suscripción de un tenant / cambiarle el plan asignado.
        Route::post('tenants/{id}/subscription', [TenantSubscriptionController::class, 'store']);
        Route::put('tenants/{id}/subscription', [TenantSubscriptionController::class, 'update']);
        // Fase B.2.5 — gestión manual de suscripciones y pagos.
        Route::post('tenants/{id}/invoices/{invoiceId}/mark-paid', [TenantSubscriptionController::class, 'marcarPagado']);
        Route::post('tenants/{id}/invoices/{invoiceId}/vouchers', [TenantSubscriptionController::class, 'subirVoucher']);
        Route::post('tenants/{id}/invoices/{invoiceId}/vouchers/{voucherId}/verify', [TenantSubscriptionController::class, 'verificarVoucher']);
        Route::post('tenants/{id}/invoices/{invoiceId}/vouchers/{voucherId}/reject', [TenantSubscriptionController::class, 'rechazarVoucher']);
        Route::post('tenants/{id}/suspend', [TenantSubscriptionController::class, 'suspender']);
        Route::post('tenants/{id}/reactivate', [TenantSubscriptionController::class, 'reactivar']);
        Route::post('tenants/{id}/subscription/toggle-automatic', [TenantSubscriptionController::class, 'toggleAutomatica']);

        // Fase C.1 — backups on-demand por tenant.
        Route::get('tenants/{id}/backups', [TenantBackupController::class, 'index']);
        Route::post('tenants/{id}/backups', [TenantBackupController::class, 'store']);

        // Fase C.4 — re-verificación de integridad bajo demanda (además de la automática
        // que ya corre al crear cada backup, dentro de TenantBackupService::ejecutarDump()).
        Route::post('tenants/{id}/backups/{backupId}/verify', [TenantBackupController::class, 'verify']);

        // Fase C.3 — restauración, 2 pasos (nunca un solo POST): preview genera un token,
        // confirm lo exige en la URL para ejecutar de verdad.
        Route::post('tenants/{id}/backups/{backupId}/restore-preview', [TenantRestoreController::class, 'preview']);
        Route::post('tenants/{id}/restores/{confirmToken}/confirm', [TenantRestoreController::class, 'confirm']);

        // Vista global de auditoría — todas las acciones sensibles ya quedaban registradas
        // por AuditLogger::log() desde fases anteriores, este es el primer endpoint para
        // leerlas.
        Route::get('audit-logs', [CentralAuditLogController::class, 'index']);
    });
});

Route::group([
    'middleware' => ['tenant', 'tenant.active', 'tenant.subscription', 'tenant.token', 'auth:api'],
], function ($router) {

    // Protected routes go here
    Route::resource("roles", RoleController::class);

    Route::post("users/{id}", [UserController::class, 'update']); //como no funciona el resource para el metodo update con PUT lo hago asi, ya que el frontend envia por POST el fromData porque tiene imagenes
    Route::resource("users", UserController::class);

    //categories
    Route::post("categories/{id}", [CategorieController::class, 'update']);
    Route::resource("categories", CategorieController::class);

    // Módulo Caja — Fase 0 (plan-modulo-caja.md §3): catálogos base.
    Route::resource("payment-methods", PaymentMethodController::class);
    Route::resource("suppliers", SupplierController::class);
    Route::resource("cash-concepts", CashConceptController::class);

    // Módulo Caja — Fase 5 (?active=1 puebla los filtros de history.vue) +
    // CRUD real de sucursales (2026-08-17, ver BranchController). CRUD real
    // de cajas (2026-08-20, ver CashRegisterController) — cierra el gap
    // documentado en CLAUDE.md: sin esto no había forma de crear la primera
    // caja de un tenant real, "Turno Activo" solo mostraba "No hay cajas
    // disponibles" sin ningún botón para arreglarlo.
    Route::resource("branches", BranchController::class);
    Route::resource("cash-registers", CashRegisterController::class);

    // Módulo de series de comprobantes. tipos-comprobante es solo lectura
    // (catálogo seed-only, sin CRUD) — mismo patrón ?active=1 que branches/
    // payment-methods para poblar selectores.
    Route::get("tipos-comprobante", [TipoComprobanteController::class, 'index']);
    Route::resource("series-comprobante", SerieComprobanteController::class);

    // Módulo Caja — Fase 2 (plan-modulo-caja.md §6, §9): apertura/cierre de
    // sesión. Las 3 rutas exigen cash.open_session a nivel de ruta (defensa
    // en profundidad, no solo gateo de menú en el frontend) — mismo criterio
    // que las rutas de Amortizaciones con permission: explícito.
    // cash.close_others_session se valida inline dentro de close(), no acá,
    // porque solo aplica al caso puntual de cerrar la sesión de un tercero.
    Route::get("cash/status", [CashSessionController::class, 'status'])
        ->middleware('permission:cash.open_session');
    Route::post("cash/open", [CashSessionController::class, 'open'])
        ->middleware('permission:cash.open_session');
    Route::post("cash/close", [CashSessionController::class, 'close'])
        ->middleware('permission:cash.open_session');

    // Módulo Caja — Fase 4 (plan-modulo-caja.md §5 regla #1, #4, §6):
    // movimientos manuales. Mismo criterio de permiso a nivel de ruta que
    // Fase 2 — cash.open_session es la puerta de entrada mínima al módulo,
    // cash.approve_expenses gatea específicamente aprobar/rechazar.
    Route::post("cash/movements", [CashMovementController::class, 'store'])
        ->middleware('permission:cash.open_session');
    Route::put("cash/movements/{id}", [CashMovementController::class, 'update'])
        ->middleware('permission:cash.open_session');
    Route::delete("cash/movements/{id}", [CashMovementController::class, 'destroy'])
        ->middleware('permission:cash.open_session');
    Route::post("cash/movements/{id}/approve", [CashMovementController::class, 'approve'])
        ->middleware('permission:cash.approve_expenses');
    Route::post("cash/movements/{id}/reject", [CashMovementController::class, 'reject'])
        ->middleware('permission:cash.approve_expenses');
    Route::get("cash/counterparty-search", [CashMovementController::class, 'buscarContraparte'])
        ->middleware('permission:cash.open_session');

    // Módulo Caja — Fase 5 (plan-modulo-caja.md §9, §11): reportes.
    // cash.open_session|cash.view_all — cualquiera con acceso al módulo
    // puede consultar; CashVisibilityResolver (dentro del controller) decide
    // si ve solo lo propio o todo. "pdf-range-url" va ANTES de
    // "cash/sessions/{id}" — mismo criterio que "clients/credit-summary-list"
    // más abajo (mismo número de segmentos que la ruta con parámetro).
    Route::get("cash/sessions/pdf-range-url", [CashSessionController::class, 'pdfRangeSignedUrl'])
        ->middleware('permission:cash.open_session|cash.view_all');
    Route::get("cash/sessions", [CashSessionController::class, 'index'])
        ->middleware('permission:cash.open_session|cash.view_all');
    Route::get("cash/sessions/{id}/pdf-url", [CashSessionController::class, 'pdfSignedUrl'])
        ->middleware('permission:cash.open_session|cash.view_all');
    Route::get("cash/sessions/{id}", [CashSessionController::class, 'show'])
        ->middleware('permission:cash.open_session|cash.view_all');

    // Dashboard admin (Paso 2) — binario, exclusivo de cash.view_all, sin
    // pasar por CashVisibilityResolver (ver conversación de esta fase).
    Route::get("cash/dashboard", [CashSessionController::class, 'dashboard'])
        ->middleware('permission:cash.view_all');

    // Export Excel de movimientos (Paso 5) — misma visibilidad que el historial.
    Route::get("cash/movements/export", [CashMovementController::class, 'export'])
        ->middleware('permission:cash.open_session|cash.view_all');

    //company
    Route::resource("company", CompanyController::class);

    //product
    Route::get("products/config", [ProductController::class, 'config']); //para traer las categorias

    Route::get("catalogs/tributarios", [ProductController::class, 'catalogsTributarios']); //para traer las catalogsTributarios
    Route::post("products/{id}", [ProductController::class, 'update']); //para editar y tiene imagenes
    Route::resource("products", ProductController::class);

    //client
    Route::get('/search-document/{type}/{number}', [ClientController::class, 'searchDocument']); //es para el autocomplete de ruc y dni

    // Cuentas por Cobrar — vista A (Módulo Amortizaciones — Fase 7, §3.11/§4).
    // Debe ir ANTES de Route::resource("clients", ...): mismo criterio que
    // sales/config más abajo — "clients/credit-summary-list" tiene el mismo
    // número de segmentos que "clients/{client}" (show), así que si el
    // resource se registra primero, Laravel la matchea ahí y "credit-summary-list"
    // se interpreta como un id de cliente.
    Route::get("clients/credit-summary-list", [CreditReceivablesController::class, 'creditSummaryList']);

    Route::resource("clients", ClientController::class);

    //sales
    Route::get("sales/config", [SaleController::class, 'config']);
    Route::post("sales/index", [SaleController::class, 'index']);
    // Módulo de series de comprobantes — preview en vivo de la serie
    // resuelta (register.vue/edit.vue), antes de "sales/{sale}" para que
    // "serie-preview" no se interprete como un id.
    Route::get("sales/serie-preview", [SaleController::class, 'previewSerieComprobante']);
    Route::resource("sales", SaleController::class);

    Route::resource("sale_details", SaleDetailController::class);
    Route::resource("sale_payments", SalePaymentController::class);
    Route::post("enviarSunat", [FacturacionElectronicaController::class, 'enviarSunat']);

    // notas de crédito/débito
    Route::get("notas/config", [NotaElectronicaController::class, 'config']);
    Route::get("notas/buscar-venta", [NotaElectronicaController::class, 'buscarVenta']);
    Route::get("notas", [NotaElectronicaController::class, 'index']);
    Route::get("notas/{id}", [NotaElectronicaController::class, 'show']);
    Route::post("notas", [NotaElectronicaController::class, 'store']);
    Route::post("notas/preview", [NotaElectronicaController::class, 'preview']);
    Route::post("notas/enviar-sunat", [NotaElectronicaController::class, 'enviarNotaSunat']);

    // adelantos (anticipos de cliente) — permission: explícito a nivel de
    // ruta, mismo criterio que Caja/Amortizaciones (defensa en profundidad,
    // no solo gateo de menú/router en el frontend — hallazgo de auditoría
    // del módulo, 2026-08-21). Los 3 nombres coinciden con
    // router/routes.ts y menu-items.ts del frontend.
    //
    // clients/{id}/advances (availableForClient) queda SIN permission: a
    // propósito: la usa el checkout de sale/register.vue para poblar el
    // picker de "adelantos disponibles" al aplicar un anticipo — no es
    // parte de las pantallas del módulo Adelantos (esas sí están detrás de
    // list_advance en el frontend), y sales/* en sí no tiene ningún
    // permission: a nivel de ruta todavía (ver nota en CLAUDE.md). Gatearla
    // habría bloqueado aplicar un adelanto al vender para cualquier
    // cajero sin list_advance asignado, sin que nada en el frontend lo
    // anticipara.
    Route::get("clients/{id}/advances", [AdvanceController::class, 'availableForClient']);
    Route::get("advances", [AdvanceController::class, 'index'])
        ->middleware('permission:list_advance');
    Route::get("advances/{id}", [AdvanceController::class, 'show'])
        ->middleware('permission:list_advance');
    Route::post("advances", [AdvanceController::class, 'store'])
        ->middleware('permission:register_advance');
    Route::post("advances/{id}/refund", [AdvanceController::class, 'refund'])
        ->middleware('permission:refund_advance');
    // Tier 2 (hallazgo de auditoría, 2026-08-21): corrige el tratamiento
    // tributario de un adelanto ya aceptado (anula NC motivo 01 + reemite)
    // — reusa register_advance, es una variante de "registrar", no un
    // permiso nuevo.
    Route::post("advances/{id}/corregir", [AdvanceController::class, 'corregir'])
        ->middleware('permission:register_advance');

    // cronograma de cuotas (Módulo Amortizaciones — Fase 3, solo cuotas_fijas)
    Route::post("sales/{sale}/installments/preview", [CreditInstallmentController::class, 'preview']);
    // Mismo cálculo que la línea de arriba, pero sin requerir una venta ya
    // persistida — usado por register.vue (Fase 8) para el cronograma
    // sugerido antes de guardar la venta.
    Route::post("installments/schedule-preview", [CreditInstallmentController::class, 'previewSchedule']);
    Route::post("sales/{sale}/installments", [CreditInstallmentController::class, 'store']);
    Route::patch("installments/{installment}", [CreditInstallmentController::class, 'update']);
    Route::post("installments/{installment}/anular", [CreditInstallmentController::class, 'anular'])
        ->middleware('permission:anular-cuota-credito');

    // amortizaciones — pagos a cuenta de ventas a crédito (Módulo Amortizaciones — Fase 4)
    Route::post("clients/{client}/payments/preview", [CreditPaymentController::class, 'preview']);
    Route::post("clients/{client}/payments", [CreditPaymentController::class, 'store']);
    Route::post("payment-receipts/{receipt}/anular", [CreditPaymentController::class, 'anular'])
        ->middleware('permission:anular-pago-credito');
    Route::post("sales/{sale}/refund", [CreditPaymentController::class, 'refund'])
        ->middleware('permission:liquidar-devolucion-credito');
    Route::post("sales/{sale}/replace", [CreditPaymentController::class, 'replace'])
        ->middleware('permission:reemplazar-comprobante-credito');
    Route::get("clients/{client}/credit-summary", [CreditPaymentController::class, 'creditSummary']);

    // Historial de recibos de pago de un cliente — solo lectura, mismo
    // criterio que creditSummary arriba: sin permission: nuevo, alcanza con
    // auth:api (Módulo Amortizaciones — pendiente §3.10/§4 cerrado ahora).
    Route::get("clients/{client}/payment-receipts", [PaymentReceiptController::class, 'index']);

    // Cuentas por Cobrar — vista B (Módulo Amortizaciones — Fase 7, §3.11/§4).
    // Sin conflicto de orden con Route::resource: "credit-sales" es una raíz
    // propia, no cuelga de "clients/{client}" ni de "sales/{sale}".
    Route::get("credit-sales", [CreditReceivablesController::class, 'creditSales']);

    // URL firmada temporal para ver/imprimir el PDF de la nota (ver notas-pdf/{id} más abajo)
    Route::get("notas-pdf-url/{id}", [NotaController::class, 'pdfSignedUrl']);

    // URL firmada temporal para ver/imprimir el PDF del comprobante (ver sales-pdf/{id} más abajo)
    Route::get("sales-pdf-url/{id}", [SaleController::class, 'pdfSignedUrl']);

    // URL firmada temporal para ver/imprimir el PDF del recibo de pago (ver payment-receipts-pdf/{id} más abajo)
    Route::get("payment-receipts-pdf-url/{id}", [PaymentReceiptController::class, 'pdfSignedUrl']);

    // Cotizaciones Comerciales — módulo nuevo, sin efecto fiscal ni de
    // stock (reemplaza a sales.state_sale, retirado). Los 4 nombres de
    // permiso coinciden con router/routes.ts y menu-items.ts del frontend.
    Route::get("commercial-quotes", [CommercialQuoteController::class, 'index'])
        ->middleware('permission:list_commercial_quote');
    Route::post("commercial-quotes", [CommercialQuoteController::class, 'store'])
        ->middleware('permission:register_commercial_quote');
    Route::get("commercial-quotes/{id}", [CommercialQuoteController::class, 'show'])
        ->middleware('permission:list_commercial_quote');
    Route::put("commercial-quotes/{id}", [CommercialQuoteController::class, 'update'])
        ->middleware('permission:edit_commercial_quote');
    Route::get("commercial-quotes/{id}/for-sale", [CommercialQuoteController::class, 'paraVenta'])
        ->middleware('permission:convert_commercial_quote');
    Route::post("commercial-quotes/{id}/mark-converted", [CommercialQuoteController::class, 'marcarConvertida'])
        ->middleware('permission:convert_commercial_quote');

    // Anticipos — cobrar un adelanto para arrancar el trabajo de una
    // cotización, antes de que exista la venta final. Mismo patrón que
    // reservas/{id}/anticipos (Agencia de Viajes). Reusa edit_commercial_quote
    // (etiquetar un anticipo es una forma de editar el estado de la
    // cotización) en vez de un permiso nuevo dedicado.
    Route::post("commercial-quotes/{id}/anticipos", [CommercialQuoteAnticipoController::class, 'store'])
        ->middleware('permission:edit_commercial_quote');
    Route::delete("commercial-quote-anticipos/{id}", [CommercialQuoteAnticipoController::class, 'destroy'])
        ->middleware('permission:edit_commercial_quote');

    // URL firmada temporal para ver/imprimir el PDF de la cotización comercial (ver commercial-quotes-pdf/{id} más abajo)
    Route::get("commercial-quotes-pdf-url/{id}", [CommercialQuoteController::class, 'pdfSignedUrl']);

    // ═══════════════════════════════════════════════════════════════
    // Vertical Agencia de Viajes — maestros (Sesión 11a). Primera capa API
    // real del vertical (Sesiones 0-10 fueron solo migraciones/modelos/
    // seeders). Solo existen tablas reales cuando giro=agencia_viajes —
    // estas rutas quedan registradas para todo tenant (mismo criterio que
    // el resto del API), pero un tenant retail nunca las va a poder usar
    // (ni le van a aparecer en su menú, gateadas por los 5 permisos nuevos).
    // Permisos dot-notation, mismo patrón que 'cash.*' — defensa en
    // profundidad a nivel de ruta, no solo gateo de menú en el frontend.
    // ═══════════════════════════════════════════════════════════════
    Route::get("proveedor-tipos", [ProveedorTipoConfigController::class, 'index'])
        ->middleware('permission:agencia.proveedores');
    Route::post("proveedor-tipos/{id}/toggle", [ProveedorTipoConfigController::class, 'toggle'])
        ->middleware('permission:agencia.proveedores');

    // Consolidación de hoteles — catálogo central de amenidades, solo
    // lectura (mismo criterio que proveedor-tipos).
    Route::get("amenidades", [AmenidadController::class, 'index'])
        ->middleware('permission:agencia.proveedores');

    Route::post("proveedores/{id}", [ProveedorController::class, 'update'])
        ->middleware('permission:agencia.proveedores');
    Route::resource("proveedores", ProveedorController::class)
        ->middleware('permission:agencia.proveedores');
    Route::get("proveedores/{id}/servicios", [ProveedorServicioController::class, 'index'])
        ->middleware('permission:agencia.proveedores');
    Route::post("proveedores/{id}/servicios", [ProveedorServicioController::class, 'store'])
        ->middleware('permission:agencia.proveedores');
    Route::delete("proveedores/{id}/servicios/{servicioId}", [ProveedorServicioController::class, 'destroy'])
        ->middleware('permission:agencia.proveedores');
    // Mantenida pese a la consolidación de hoteles — usada por el flujo de
    // opcion_mayorista ("usar tarifa registrada"), fuera de alcance de esta
    // sesión, ver comentario en ProveedorController::tarifasHotel().
    Route::get("proveedores/{id}/tarifas-hotel", [ProveedorController::class, 'tarifasHotel'])
        ->middleware('permission:agencia.proveedores');
    Route::get("proveedor-servicios/{id}/tarifas", [ProveedorTarifaController::class, 'index'])
        ->middleware('permission:agencia.proveedores');
    Route::post("proveedor-servicios/{id}/tarifas", [ProveedorTarifaController::class, 'store'])
        ->middleware('permission:agencia.proveedores');
    Route::put("proveedor-tarifas/{id}", [ProveedorTarifaController::class, 'update'])
        ->middleware('permission:agencia.proveedores');
    Route::delete("proveedor-tarifas/{id}", [ProveedorTarifaController::class, 'destroy'])
        ->middleware('permission:agencia.proveedores');
    // 26-ago-2026 — retiro del catálogo activo sin borrar historial, ver
    // ProveedorTarifaController::desactivar()/activar().
    Route::patch("proveedor-tarifas/{id}/desactivar", [ProveedorTarifaController::class, 'desactivar'])
        ->middleware('permission:agencia.proveedores');
    Route::patch("proveedor-tarifas/{id}/activar", [ProveedorTarifaController::class, 'activar'])
        ->middleware('permission:agencia.proveedores');

    Route::post("destinos-atractivos/{id}", [DestinoAtractivoController::class, 'update'])
        ->middleware('permission:agencia.destinos');
    Route::get("destinos-atractivos", [DestinoAtractivoController::class, 'index'])
        ->middleware('permission:agencia.destinos');
    Route::post("destinos-atractivos", [DestinoAtractivoController::class, 'store'])
        ->middleware('permission:agencia.destinos');
    Route::delete("destinos-atractivos/{id}", [DestinoAtractivoController::class, 'destroy'])
        ->middleware('permission:agencia.destinos');
    Route::delete("destinos-atractivos/{id}/fotos", [DestinoAtractivoController::class, 'eliminarFoto'])
        ->middleware('permission:agencia.destinos');
    Route::patch("destinos-atractivos/{id}/fotos/orden", [DestinoAtractivoController::class, 'ordenarFotos'])
        ->middleware('permission:agencia.destinos');
    Route::get("destinos-atractivos/{id}/servicios", [DestinoServicioController::class, 'index'])
        ->middleware('permission:agencia.destinos');
    Route::post("destinos-atractivos/{id}/servicios", [DestinoServicioController::class, 'store'])
        ->middleware('permission:agencia.destinos');
    Route::delete("destino-servicio/{id}", [DestinoServicioController::class, 'destroy'])
        ->middleware('permission:agencia.destinos');
    Route::patch("destino-servicio/{id}/mover", [DestinoServicioController::class, 'mover'])
        ->middleware('permission:agencia.destinos');
    Route::post("destino-servicio/{id}/fusionar", [DestinoServicioController::class, 'fusionar'])
        ->middleware('permission:agencia.destinos');
    Route::resource("servicios", ServicioController::class)
        ->middleware('permission:agencia.destinos');

    Route::resource("temporadas", TemporadaController::class)
        ->middleware('permission:agencia.temporadas');
    Route::get("temporadas/{id}/ocurrencias", [TemporadaOcurrenciaController::class, 'index'])
        ->middleware('permission:agencia.temporadas');
    Route::post("temporadas/{id}/ocurrencias", [TemporadaOcurrenciaController::class, 'store'])
        ->middleware('permission:agencia.temporadas');

    Route::resource("guias", GuiaController::class)
        ->middleware('permission:agencia.guias');
    Route::get("guias/{id}/tarifas", [GuiaTarifaController::class, 'index'])
        ->middleware('permission:agencia.guias');
    Route::post("guias/{id}/tarifas", [GuiaTarifaController::class, 'store'])
        ->middleware('permission:agencia.guias');
    Route::put("guia-tarifas/{id}", [GuiaTarifaController::class, 'update'])
        ->middleware('permission:agencia.guias');
    Route::delete("guia-tarifas/{id}", [GuiaTarifaController::class, 'destroy'])
        ->middleware('permission:agencia.guias');
    // 29-ago-2026 — retiro del catálogo activo sin borrar historial, mismo
    // patrón que proveedor-tarifas (ver GuiaTarifaController::desactivar()/
    // activar()).
    Route::patch("guia-tarifas/{id}/desactivar", [GuiaTarifaController::class, 'desactivar'])
        ->middleware('permission:agencia.guias');
    Route::patch("guia-tarifas/{id}/activar", [GuiaTarifaController::class, 'activar'])
        ->middleware('permission:agencia.guias');

    Route::get("configuracion-agencia", [ConfiguracionAgenciaController::class, 'show'])
        ->middleware('permission:agencia.configuracion');
    Route::put("configuracion-agencia", [ConfiguracionAgenciaController::class, 'update'])
        ->middleware('permission:agencia.configuracion');

    // Módulo 12 — plan-modulo-codigos-numeracion.md. Reusa el permiso
    // 'agencia.configuracion' (mismo área de configuración comercial que
    // Configuración de Agencia, sin permiso nuevo dedicado).
    Route::get("configuracion-codigos", [ConfiguracionCodigosController::class, 'index'])
        ->middleware('permission:agencia.configuracion');
    Route::put("configuracion-codigos/{tipo}", [ConfiguracionCodigosController::class, 'update'])
        ->middleware('permission:agencia.configuracion');
    Route::get("configuracion-codigos/{tipo}/previsualizar", [ConfiguracionCodigosController::class, 'previsualizar'])
        ->middleware('permission:agencia.configuracion');

    Route::get("cuentas-bancarias", [CuentaBancariaController::class, 'index'])
        ->middleware('permission:agencia.configuracion');
    Route::post("cuentas-bancarias", [CuentaBancariaController::class, 'store'])
        ->middleware('permission:agencia.configuracion');
    Route::put("cuentas-bancarias/{id}", [CuentaBancariaController::class, 'update'])
        ->middleware('permission:agencia.configuracion');
    Route::delete("cuentas-bancarias/{id}", [CuentaBancariaController::class, 'destroy'])
        ->middleware('permission:agencia.configuracion');

    // Sesión pdf-cotizacion — documento aparte, mismo contenido para toda
    // cotización del tenant (no depende de ninguna alternativa puntual).
    Route::get("condiciones-generales/pdf", [CondicionesGeneralesController::class, 'pdf'])
        ->middleware('permission:agencia.cotizaciones');

    // Sesión 11b2 — catálogo de paquetes/tours de plantilla.
    Route::get("paquetes-plantilla", [PaquetePlantillaController::class, 'index'])
        ->middleware('permission:agencia.paquetes');
    Route::post("paquetes-plantilla", [PaquetePlantillaController::class, 'store'])
        ->middleware('permission:agencia.paquetes');
    Route::get("paquetes-plantilla/{id}", [PaquetePlantillaController::class, 'show'])
        ->middleware('permission:agencia.paquetes');
    Route::post("paquetes-plantilla/{id}", [PaquetePlantillaController::class, 'update'])
        ->middleware('permission:agencia.paquetes');
    Route::delete("paquetes-plantilla/{id}", [PaquetePlantillaController::class, 'destroy'])
        ->middleware('permission:agencia.paquetes');
    // Sesión 11m — duplicar tour/paquete completo (datos generales +
    // itinerario + incluye + hoteles), copia nace inactiva.
    Route::post("paquetes-plantilla/{id}/duplicar", [PaquetePlantillaController::class, 'duplicar'])
        ->middleware('permission:agencia.paquetes');

    Route::get("paquetes-plantilla/{id}/items", [PaquetePlantillaItemController::class, 'index'])
        ->middleware('permission:agencia.paquetes');
    Route::post("paquetes-plantilla/{id}/items", [PaquetePlantillaItemController::class, 'store'])
        ->middleware('permission:agencia.paquetes');
    Route::delete("paquete-plantilla-items/{id}", [PaquetePlantillaItemController::class, 'destroy'])
        ->middleware('permission:agencia.paquetes');

    Route::get("paquetes-plantilla/{id}/itinerario", [TourItinerarioItemController::class, 'index'])
        ->middleware('permission:agencia.paquetes');
    Route::post("paquetes-plantilla/{id}/itinerario", [TourItinerarioItemController::class, 'store'])
        ->middleware('permission:agencia.paquetes');
    Route::put("tour-itinerario-items/{id}", [TourItinerarioItemController::class, 'update'])
        ->middleware('permission:agencia.paquetes');
    Route::delete("tour-itinerario-items/{id}", [TourItinerarioItemController::class, 'destroy'])
        ->middleware('permission:agencia.paquetes');
    Route::post("paquetes-plantilla/{id}/itinerario/reordenar", [TourItinerarioItemController::class, 'reordenar'])
        ->middleware('permission:agencia.paquetes');

    // ═══════════════════════════════════════════════════════════════
    // Vertical Agencia de Viajes — cotizador (Sesión 11b). Permiso propio
    // 'agencia.cotizaciones', DISTINTO de 'agencia.proveedores' (11a) —
    // cotizar es una operación de venta diaria (cualquier vendedor), el
    // catálogo de proveedores es más admin-level. Ver migración
    // 2026_07_28_180200_add_agencia_cotizaciones_permission.php para el
    // razonamiento completo.
    // ═══════════════════════════════════════════════════════════════
    // Biblioteca del cotizador — antes de "cotizaciones/{id}", no colisiona
    // (segmentos distintos) pero se agrupa acá por pertenecer al mismo flujo.
    Route::get("proveedor-tarifas", [ProveedorTarifaController::class, 'biblioteca'])
        ->middleware('permission:agencia.cotizaciones');
    // Sesión 11b3 — biblioteca unificada (tour/paquete/proveedor_tarifa en un
    // solo endpoint, ver BibliotecaCotizadorController). No reemplaza la ruta
    // de arriba, que sigue usada por paquetes/detalle.vue.
    Route::get("biblioteca-cotizador", [BibliotecaCotizadorController::class, 'index'])
        ->middleware('permission:agencia.cotizaciones');

    Route::get("cotizaciones", [CotizacionController::class, 'index'])
        ->middleware('permission:agencia.cotizaciones');
    Route::post("cotizaciones", [CotizacionController::class, 'store'])
        ->middleware('permission:agencia.cotizaciones');
    Route::get("cotizaciones/{id}", [CotizacionController::class, 'show'])
        ->middleware('permission:agencia.cotizaciones');
    Route::put("cotizaciones/{id}", [CotizacionController::class, 'update'])
        ->middleware('permission:agencia.cotizaciones');
    Route::put("cotizaciones/{id}/pasajeros", [CotizacionController::class, 'actualizarPasajeros'])
        ->middleware('permission:agencia.cotizaciones');
    Route::delete("cotizaciones/{id}", [CotizacionController::class, 'destroy'])
        ->middleware('permission:agencia.cotizaciones');

    Route::post("cotizaciones/{id}/alternativas", [AlternativaController::class, 'store'])
        ->middleware('permission:agencia.cotizaciones');
    Route::put("alternativas/{id}", [AlternativaController::class, 'update'])
        ->middleware('permission:agencia.cotizaciones');
    Route::delete("alternativas/{id}", [AlternativaController::class, 'destroy'])
        ->middleware('permission:agencia.cotizaciones');
    // Sesión 11h — clona la alternativa completa (ítems + opciones de
    // mayorista) en una alternativa nueva de la misma cotización.
    Route::post("alternativas/{id}/duplicar", [AlternativaController::class, 'duplicar'])
        ->middleware('permission:agencia.cotizaciones');
    // Sesión pdf-cotizacion — PDF comercial de una alternativa puntual.
    Route::get("alternativas/{id}/pdf", [AlternativaController::class, 'pdf'])
        ->middleware('permission:agencia.cotizaciones');

    Route::post("alternativas/{id}/items", [AlternativaItemController::class, 'store'])
        ->middleware('permission:agencia.cotizaciones');
    // Recalculo en vivo del formulario de pasaje aéreo (PasajeAereoForm.vue),
    // sin persistir — antes de "alternativa-items/{id}" para que
    // "items/preview-pasaje-aereo" no colisione con ninguna otra ruta.
    Route::post("alternativas/{id}/items/preview-pasaje-aereo", [AlternativaItemController::class, 'previewPasajeAereo'])
        ->middleware('permission:agencia.cotizaciones');
    Route::put("alternativa-items/{id}", [AlternativaItemController::class, 'update'])
        ->middleware('permission:agencia.cotizaciones');
    Route::delete("alternativa-items/{id}", [AlternativaItemController::class, 'destroy'])
        ->middleware('permission:agencia.cotizaciones');
    // Sesión 11q — ítem manual flexible: edición estructural completa
    // (descripción/proveedor/costo/cantidad/pax) y promoción a proveedor real.
    Route::put("alternativa-items/{id}/manual", [AlternativaItemController::class, 'actualizarManual'])
        ->middleware('permission:agencia.cotizaciones');
    // Auditoría del módulo Reservas/Cotizador (2026-08-27) — edición
    // estructural completa de un pasaje aéreo suelto, mismo criterio que
    // actualizarManual() arriba (antes de esto no existía ninguna forma de
    // corregir un pasaje aéreo ya cargado, solo borrar y recrear).
    Route::put("alternativa-items/{id}/pasaje-aereo", [AlternativaItemController::class, 'actualizarPasajeAereo'])
        ->middleware('permission:agencia.cotizaciones');
    Route::post("alternativa-items/{id}/promover-a-proveedor", [AlternativaItemController::class, 'promoverAProveedor'])
        ->middleware('permission:agencia.cotizaciones');

    // Sesión 11b3 (Parte A) — cargar un tour_simple/paquete_combo entero en
    // la alternativa (explota todos sus ítems, ver AlternativaItemController::
    // desdePlantilla()) + reasignación de día (lienzo día-por-día, §7.1).
    Route::post("alternativas/{id}/items/desde-plantilla", [AlternativaItemController::class, 'desdePlantilla'])
        ->middleware('permission:agencia.cotizaciones');
    Route::put("alternativa-items/{id}/dia", [AlternativaItemController::class, 'reasignarDia'])
        ->middleware('permission:agencia.cotizaciones');
    Route::put("alternativas/{id}/items/mover-bloque", [AlternativaItemController::class, 'moverBloque'])
        ->middleware('permission:agencia.cotizaciones');

    Route::get("alternativas/{id}/opciones-mayorista", [OpcionMayoristaController::class, 'index'])
        ->middleware('permission:agencia.cotizaciones');
    Route::post("alternativas/{id}/opciones-mayorista", [OpcionMayoristaController::class, 'store'])
        ->middleware('permission:agencia.cotizaciones');
    Route::post("opciones-mayorista/{id}/elegir", [OpcionMayoristaController::class, 'elegir'])
        ->middleware('permission:agencia.cotizaciones');
    Route::match(['get', 'post'], "opciones-mayorista/{id}/hoteles", [OpcionMayoristaController::class, 'hoteles'])
        ->middleware('permission:agencia.cotizaciones');
    Route::match(['get', 'post'], "opciones-mayorista/{id}/opcionales", [OpcionMayoristaController::class, 'opcionales'])
        ->middleware('permission:agencia.cotizaciones');

    // Sesión 12e — biblioteca de contenido reutilizable (§9.1 de la
    // auditoría). Solo búsqueda/creación, ver ContenidoTourController.
    Route::get("contenido-tour", [ContenidoTourController::class, 'index'])
        ->middleware('permission:agencia.cotizaciones');
    Route::post("contenido-tour", [ContenidoTourController::class, 'store'])
        ->middleware('permission:agencia.cotizaciones');

    // Sesión 12f-1 — backend para la UI multi-destino (§7.1). Solo index/
    // store, lo que necesita el botón "+ Agregar destino" de 12f-2.
    Route::get("alternativas/{id}/destinos", [AlternativaDestinoController::class, 'index'])
        ->middleware('permission:agencia.cotizaciones');
    Route::post("alternativas/{id}/destinos", [AlternativaDestinoController::class, 'store'])
        ->middleware('permission:agencia.cotizaciones');
    // Sesión 12f-2 — editar/borrar un chip de destino.
    Route::put("alternativas/{alternativaId}/destinos/{id}", [AlternativaDestinoController::class, 'update'])
        ->middleware('permission:agencia.cotizaciones');
    Route::delete("alternativas/{alternativaId}/destinos/{id}", [AlternativaDestinoController::class, 'destroy'])
        ->middleware('permission:agencia.cotizaciones');

    // ═══════════════════════════════════════════════════════════════
    // Vertical Agencia de Viajes — reserva y pasajeros (Sesión 11c).
    // Permiso propio 'agencia.reservas', distinto de 'agencia.cotizaciones'
    // (ver 2026_07_30_100100_add_agencia_reservas_permission.php).
    // ═══════════════════════════════════════════════════════════════
    Route::post("alternativas/{id}/aceptar", [ReservaController::class, 'aceptar'])
        ->middleware('permission:agencia.reservas');

    Route::get("reservas", [ReservaController::class, 'index'])
        ->middleware('permission:agencia.reservas');
    Route::get("reservas/{id}", [ReservaController::class, 'show'])
        ->middleware('permission:agencia.reservas');
    // Sesión 11e — reporte operativo por fecha (plan-modulo-cotizaciones-reservas.md §8).
    Route::get("reporte-operativo", [ReporteOperativoController::class, 'index'])
        ->middleware('permission:agencia.reservas');
    // Sesión 11d — PDF (URL firmada, ver "reporte-operativo-pdf" fuera del grupo
    // auth:api) y Excel (descarga directa, mismo criterio que cash/movements/export).
    Route::get("reporte-operativo/pdf-url", [ReporteOperativoController::class, 'pdfSignedUrl'])
        ->middleware('permission:agencia.reservas');
    Route::get("reporte-operativo/export", [ReporteOperativoController::class, 'export'])
        ->middleware('permission:agencia.reservas');
    Route::get("reporte-operativo/filtros", [ReporteOperativoController::class, 'filtrosDisponibles'])
        ->middleware('permission:agencia.reservas');
    Route::put("reservas/{id}/cancelar", [ReservaController::class, 'cancelar'])
        ->middleware('permission:agencia.reservas');
    Route::post("reservas/{id}/sincronizar-items", [ReservaController::class, 'sincronizarItems'])
        ->middleware('permission:agencia.reservas');
    // Fase 2 del fix Cotización↔Reserva (2026-08-19).
    Route::post("reservas/{id}/reprogramar", [ReservaController::class, 'reprogramar'])
        ->middleware('permission:agencia.reservas');
    // Sesión 12h — reasignación en vivo de OpcionMayorista en ReservaItem.
    Route::post("reservas/{id}/reasignar-mayorista", [ReservaController::class, 'reasignarMayorista'])
        ->middleware('permission:agencia.reservas');
    // Facturación externa por tenant + por reserva (PEGAR-EN-CLAUDE-CODE-
    // facturacion-externa-tenant.md, 2026-08-20).
    Route::put("reservas/{id}/facturacion-externa", [ReservaController::class, 'actualizarFacturacionExterna'])
        ->middleware('permission:agencia.reservas');
    // Fase A del plan "Proceso de reserva: facturación + 3 fixes" (2026-08-19).
    // Guardia tributario (2026-08-20, complemento a 11u): preparar-factura
    // ANTES de facturar, para que el frontend pueda avisar antes de que el
    // usuario llene el modal — el guardia real (bloqueo) vive igual en
    // POST facturar, server-side, sin confiar en este preview.
    Route::get("reservas/{id}/preparar-factura", [ReservaFacturacionController::class, 'prepararFactura'])
        ->middleware('permission:agencia.reservas');
    Route::post("reservas/{id}/facturar", [ReservaFacturacionController::class, 'store'])
        ->middleware('permission:agencia.reservas');

    // Conexión Adelantos↔Reservas (Tier 0, hallazgo de auditoría del
    // módulo Adelantos, 2026-08-21) — reserva_anticipos existía desde
    // Sesión 8b sin ningún controller que la usara.
    Route::post("reservas/{id}/anticipos", [ReservaAnticipoController::class, 'store'])
        ->middleware('permission:agencia.reservas');
    Route::delete("reserva-anticipos/{id}", [ReservaAnticipoController::class, 'destroy'])
        ->middleware('permission:agencia.reservas');

    // Antes de "reserva-pasajeros/{id}" para que "pasajeros-catalogo" no
    // colisione con ningún segmento dinámico (mismo criterio ya usado con
    // "proveedor-tarifas" antes de "cotizaciones/{id}").
    Route::get("pasajeros-catalogo", [ReservaPasajeroController::class, 'buscarCatalogo'])
        ->middleware('permission:agencia.reservas');
    Route::put("reserva-pasajeros/{id}", [ReservaPasajeroController::class, 'update'])
        ->middleware('permission:agencia.reservas');
    // Fase D del plan "Proceso de reserva: facturación + 3 fixes" (2026-08-19).
    Route::delete("reserva-pasajeros/{id}", [ReservaPasajeroController::class, 'destroy'])
        ->middleware('permission:agencia.reservas');

    Route::put("reserva-items/{id}", [ReservaItemController::class, 'update'])
        ->middleware('permission:agencia.reservas');
    Route::delete("reserva-items/{id}", [ReservaItemController::class, 'destroy'])
        ->middleware('permission:agencia.reservas');

    Route::get("reserva-items/{id}/pasajeros", [ReservaItemPasajeroController::class, 'index'])
        ->middleware('permission:agencia.reservas');
    Route::post("reserva-items/{id}/pasajeros", [ReservaItemPasajeroController::class, 'store'])
        ->middleware('permission:agencia.reservas');
    Route::delete("reserva-item-pasajero/{id}", [ReservaItemPasajeroController::class, 'destroy'])
        ->middleware('permission:agencia.reservas');
    // Sesión 11d — check-in del reporte operativo. Distinto de store()/destroy() de
    // arriba (esos son la asignación pasajero↔ítem propiamente dicha): acá el vínculo
    // puede no existir todavía y se crea recién al marcar (firstOrCreate en el
    // controller), porque la mayoría de reserva_items no tiene vinculo_especifico.
    Route::post("reserva-items/{id}/pasajeros/{pasajeroId}/checkin", [ReservaItemPasajeroController::class, 'checkin'])
        ->middleware('permission:agencia.reservas');
    // Auditoría de UX/funcionalidad del módulo (2026-08-27) — vuelo
    // vendido por la AGENCIA (distinto de reserva_pasajeros.vuelo_*, que
    // es el vuelo por cuenta propia del pasajero). Mismo shape de ruta y
    // mismo criterio de materialización que checkin() arriba.
    Route::put("reserva-items/{id}/pasajeros/{pasajeroId}/vuelo", [ReservaItemPasajeroController::class, 'actualizarVuelo'])
        ->middleware('permission:agencia.reservas');

    Route::post("venta-directa", [VentaDirectaController::class, 'store'])
        ->middleware('permission:agencia.reservas');

    // Tablero de despacho — agrupa reserva_items de distintas reservas
    // que comparten tour_origen_id + fecha (ver SalidaOperativa).
    Route::get("salidas-operativas", [SalidaOperativaController::class, 'index'])
        ->middleware('permission:agencia.reservas');
    Route::get("salidas-operativas/{id}", [SalidaOperativaController::class, 'show'])
        ->middleware('permission:agencia.reservas');
    Route::put("salidas-operativas/{id}", [SalidaOperativaController::class, 'update'])
        ->middleware('permission:agencia.reservas');
    Route::put("salidas-operativas/{id}/cancelar", [SalidaOperativaController::class, 'cancelar'])
        ->middleware('permission:agencia.reservas');
    Route::post("salidas-operativas/{id}/adjuntar-item", [SalidaOperativaController::class, 'attachReservaItem'])
        ->middleware('permission:agencia.reservas');
    Route::delete("salidas-operativas/{id}/items/{reservaItemId}", [SalidaOperativaController::class, 'detachReservaItem'])
        ->middleware('permission:agencia.reservas');

    Route::middleware('auth:api')->group(function () {});
});

// Requiere URL firmada (ver SaleController::pdfSignedUrl, arriba, dentro del grupo auth:api)
Route::get("sales-pdf/{id}", [SaleController::class, 'pdf'])
    ->name('sales.pdf')
    ->middleware(['tenant', 'tenant.active', 'tenant.token', 'signed']);

// Requiere URL firmada (ver NotaController::pdfSignedUrl, arriba, dentro del grupo auth:api)
Route::get("notas-pdf/{id}", [NotaController::class, 'pdf'])
    ->name('notas.pdf')
    ->middleware(['tenant', 'tenant.active', 'tenant.token', 'signed']);

// Requiere URL firmada (ver PaymentReceiptController::pdfSignedUrl, arriba, dentro del grupo auth:api)
Route::get("payment-receipts-pdf/{id}", [PaymentReceiptController::class, 'pdf'])
    ->name('payment-receipts.pdf')
    ->middleware(['tenant', 'tenant.active', 'tenant.token', 'signed']);

// Requiere URL firmada (ver CommercialQuoteController::pdfSignedUrl, arriba, dentro del grupo auth:api)
Route::get("commercial-quotes-pdf/{id}", [CommercialQuoteController::class, 'pdf'])
    ->name('commercial-quotes.pdf')
    ->middleware(['tenant', 'tenant.active', 'tenant.token', 'signed']);

// Requiere URL firmada (ver CashSessionController::pdfSignedUrl, arriba, dentro del grupo auth:api)
Route::get("cash-sessions-pdf/{id}", [CashSessionController::class, 'pdf'])
    ->name('cash-sessions.pdf')
    ->middleware(['tenant', 'tenant.active', 'tenant.token', 'signed']);

// Requiere URL firmada (ver CashSessionController::pdfRangeSignedUrl, arriba, dentro del grupo auth:api)
Route::get("cash-sessions-pdf-range", [CashSessionController::class, 'pdfRange'])
    ->name('cash-sessions.pdf-range')
    ->middleware(['tenant', 'tenant.active', 'tenant.token', 'signed']);

// Requiere URL firmada (ver ReporteOperativoController::pdfSignedUrl, arriba, dentro del grupo auth:api)
Route::get("reporte-operativo-pdf", [ReporteOperativoController::class, 'pdf'])
    ->name('reporte-operativo.pdf')
    ->middleware(['tenant', 'tenant.active', 'tenant.token', 'signed']);






// Portal público — sin autenticación
Route::prefix('portal')->middleware(['tenant', 'tenant.active', 'tenant.subscription', 'tenant.token'])->group(function () {
    Route::get('/categories', [App\Http\Controllers\Portal\CategoryController::class, 'index']);
    Route::get('/products', [App\Http\Controllers\Portal\ProductController::class, 'index']);
    Route::get('/products/{id}', [App\Http\Controllers\Portal\ProductController::class, 'show']);

    // Crear pedido desde el carrito
    // Ruta pública para crear pedidos (permite invitados)
    Route::post('/orders', [OrderController::class, 'store']); //esta ruta es para crear el pedido y como invitado por el checkout

    Route::get('/orders', [PortalOrderController::class, 'clientOrders'])
        ->middleware('auth:client'); //esta ruta es para mostrar los pedidos en mi cuenta con autenticacion

    Route::get('/orders/{id}', [PortalOrderController::class, 'show'])
        ->middleware('auth:client'); //esta ruta es para mostrar los detalles de un pedido con autenticacion


    Route::post('/register', [PortalAuthController::class, 'register'])->middleware('throttle:6,1'); //esta ruta es para el registro del cliente por formulario clientRegister
    Route::post('/login', [PortalAuthController::class, 'login'])->middleware('throttle:6,1');
    Route::get('/me', [PortalAuthController::class, 'me']);
    // Route::post('/logout', [PortalAuthController::class, 'logout']);

    Route::get('/profile', [ProfileController::class, 'show'])
        ->middleware('auth:client');;
    Route::put('/profile', [ProfileController::class, 'update'])
        ->middleware('auth:client');;
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
        ->middleware('auth:client');;
});
