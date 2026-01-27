<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Client\ClientController;
use App\Http\Controllers\Client\CompanyController;
use App\Http\Controllers\Product\CategorieController;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Role\RoleController;
use App\Http\Controllers\Sale\SaleController;
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
    Route::get("products/config", [ProductController::class, 'config']);//para traer las categorias
    Route::post("products/{id}", [ProductController::class, 'update']);//para editar y tiene imagenes
    Route::resource("products", ProductController::class);

    //client
    Route::get('/search-document/{type}/{number}', [ClientController::class, 'searchDocument']);//es para el autocomplete de ruc y dni
    Route::resource("clients", ClientController::class);

    //sales
    Route::get("sales/config", [SaleController::class, 'config']);
    Route::resource("sales", SaleController::class);

});
