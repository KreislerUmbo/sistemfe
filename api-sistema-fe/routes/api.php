<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Client\ClientController;
use App\Http\Controllers\Client\CompanyController;
use App\Http\Controllers\OrderController;
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
    'prefix' => 'auth'
], function ($router) {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/me', [AuthController::class, 'me'])->middleware('auth:api')->name('me');
});

Route::group([
    'middleware' => 'auth:api'
], function ($router) {
    //para la parte de administracion del portal
    Route::resource('system_categories', SystemCategoryController::class);
    Route::post("system_categories/{id}", [SystemCategoryController::class, 'update']);

    Route::get("systems/config", [SystemController::class, 'config']);
    Route::resource('systems', SystemController::class);





    // Protected routes go here
    Route::resource("roles", RoleController::class);

    Route::post("users/{id}", [UserController::class, 'update']); //como no funciona el resource para el metodo update con PUT lo hago asi, ya que el frontend envia por POST el fromData porque tiene imagenes
    Route::resource("users", UserController::class);

    //categories
    Route::post("categories/{id}", [CategorieController::class, 'update']);
    Route::resource("categories", CategorieController::class);

    //company 
    Route::resource("company", CompanyController::class);

    //product
    Route::get("products/config", [ProductController::class, 'config']); //para traer las categorias

    Route::get("catalogs/tributarios", [ProductController::class, 'catalogsTributarios']); //para traer las catalogsTributarios
    Route::post("products/{id}", [ProductController::class, 'update']); //para editar y tiene imagenes
    Route::resource("products", ProductController::class);

    //client
    Route::get('/search-document/{type}/{number}', [ClientController::class, 'searchDocument']); //es para el autocomplete de ruc y dni
    Route::resource("clients", ClientController::class);

    //sales
    Route::get("sales/config", [SaleController::class, 'config']);
    Route::post("sales/index", [SaleController::class, 'index']);
    Route::resource("sales", SaleController::class);

    Route::resource("sale_details", SaleDetailController::class);
    Route::resource("sale_payments", SalePaymentController::class);
    Route::post("enviarSunat", [FacturacionElectronicaController::class, 'enviarSunat']);

    // notas de crédito/débito
    Route::get("notas/config", [NotaElectronicaController::class, 'config']);
    Route::get("notas", [NotaElectronicaController::class, 'index']);
    Route::get("notas/{id}", [NotaElectronicaController::class, 'show']);
    Route::post("notas", [NotaElectronicaController::class, 'store']);
    Route::post("notas/preview", [NotaElectronicaController::class, 'preview']);
    Route::post("notas/enviar-sunat", [NotaElectronicaController::class, 'enviarNotaSunat']);

    // URL firmada temporal para ver/imprimir el PDF de la nota (ver notas-pdf/{id} más abajo)
    Route::get("notas-pdf-url/{id}", [NotaController::class, 'pdfSignedUrl']);

    // URL firmada temporal para ver/imprimir el PDF del comprobante (ver sales-pdf/{id} más abajo)
    Route::get("sales-pdf-url/{id}", [SaleController::class, 'pdfSignedUrl']);

    Route::resource("recursos", ProductController::class);

    Route::middleware('auth:api')->group(function () {});
});

// Requiere URL firmada (ver SaleController::pdfSignedUrl, arriba, dentro del grupo auth:api)
Route::get("sales-pdf/{id}", [SaleController::class, 'pdf'])
    ->name('sales.pdf')
    ->middleware('signed');

// Requiere URL firmada (ver NotaController::pdfSignedUrl, arriba, dentro del grupo auth:api)
Route::get("notas-pdf/{id}", [NotaController::class, 'pdf'])
    ->name('notas.pdf')
    ->middleware('signed');






// Portal público — sin autenticación
Route::prefix('portal')->group(function () {
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


    Route::post('/register', [PortalAuthController::class, 'register']); //esta ruta es para el registro del cliente por formulario clientRegister
    Route::post('/login', [PortalAuthController::class, 'login']);
    Route::get('/me', [PortalAuthController::class, 'me']);
    // Route::post('/logout', [PortalAuthController::class, 'logout']);

    Route::get('/profile', [ProfileController::class, 'show'])
        ->middleware('auth:client');;
    Route::put('/profile', [ProfileController::class, 'update'])
        ->middleware('auth:client');;
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
        ->middleware('auth:client');;
});
