<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sale\SaleDetailResource;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use Illuminate\Http\Request;

class SaleDetailController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
      
        $product = $request->product;
        $sale_detail = SaleDetail::create(
            [
                "sale_id" => $request->sale_id,
                "product_id" => $product["id"],
                "product_categorie_id" => $product["categorie_id"],
                "tip_afe_igv" => $request->tip_afe_igv,
                "per_icbper" => $request->per_icbper,
                "icbper" => $request->icbper,
                "percentage_isc" => $request->percentage_isc,
                "isc" => $request->isc,
                "unidad_medida" => $request->unidad_medida,
                "quantity" => $request->quantity,
                "price_base" => $request->price_base,
                "price_final" => $request->price_final,
                "discount" => $request->discount,
                "subtotal" => $request->subtotal,
                "igv" => $request->igv,
                "description" => $request->description,
            ]
        );
        $sale = Sale::find($request->sale_id);
        $discount = $sale->discount + $sale_detail->discount;
        $igv = $sale->igv + $sale_detail->igv;
        $subtotal = $sale->subtotal + $sale_detail->subtotal;
        $total = $subtotal + $igv;

        $debt = $sale->debt + $sale_detail->subtotal+$sale_detail->igv;
        $state_payment = 1;
        if ($debt == 0) {
            $state_payment = 3;
        } else if ($sale->paid_out > 0) {
            $state_payment = 2;
        }

        $sale->update([
            "discount" => $discount,
            "igv" => $igv,
            "subtotal" => $subtotal,
            "total" => $total,
            "debt" => $debt,
            "state_payment" => $state_payment,
        ]);

       // $product = $sale_detail->product;
        $product =  Product::find($product["id"]);
         $product->update([
            "stock" => $product->stock - $sale_detail->quantity
         ]);

        return response()->json([
            "sale_detail" => SaleDetailResource::make($sale_detail),
            "code" => 200,
            "message" => "Venta actualizada correctamente"
        ]);
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
        $sale_detail = SaleDetail::find($id);
        $sale_detail->delete();

        $sale = Sale::find($sale_detail->sale_id);
        $paid_out =(float)$sale->paid_out;
        $state_payment = 1;
        if($sale->total-($sale_detail->price_final * $sale_detail->quantity) == $paid_out){
            $state_payment = 3;
        }else if($paid_out > 0){
            $state_payment = 2;
        }

        $sale->update([
            "discount" => $sale->discount - $sale_detail->discount,
            "igv" => $sale->igv - $sale_detail->igv,
            "subtotal" => $sale->subtotal - $sale_detail->subtotal,
            "total" => $sale->total - ($sale_detail->price_final * $sale_detail->quantity),
            "debt" => $sale->debt - ($sale_detail->price_final * $sale_detail->quantity),
            "state_payment" => $state_payment
        ]);

        $product = $sale_detail->product;
        $product->update([
            "stock" => $product->stock + $sale_detail->quantity
        ]);

        return response()->json([
            "sale_detail" => $id,
            "code" => 200,
            "message" => "el producto se elimino correctamente"
        ]);
    }
}
