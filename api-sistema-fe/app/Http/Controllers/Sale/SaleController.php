<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Http\Resources\Client\ClientCollection;
use App\Http\Resources\Product\ProductCollection;
use App\Models\Client\Client;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    public function config(){
        $today=today();
        $clients= Client::where("state",1)->orderBy("full_name","asc")->get();
        $products = Product::where("state",1)->where("is_especial_nota",0)->orderBy("title","asc")->get();

        $n_transaccion = 1000;
        $sale=Sale::orderBy("id","desc")->first();
        if ($sale) { //si sale no es nulo entonces se obtiene el correlativo y se le suma 1
            $n_transaccion = $sale->n_transaccion + 1;
        }

        return response()->json([
            "clients" => ClientCollection::make($clients), 
            "products" => ProductCollection::make($products),
            "today" => $today->format("Y-m-d"),
            "n_transaccion" => str_pad($n_transaccion, 8, "0", STR_PAD_LEFT), //correlativo 00001
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
