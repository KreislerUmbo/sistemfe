<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Http\Resources\Client\ClientCollection;
use App\Http\Resources\Product\ProductCollection;
use App\Http\Resources\Sale\SaleCollection;
use App\Http\Resources\Sale\SaleResource;
use App\Models\Client\Client;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Models\Sale\SalePayment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpParser\Node\Stmt\TryCatch;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search_product = $request->search_product;
        $categorie_id = $request->categorie_id;
        $search = $request->search;
        $search_client = $request->search_client;
        $state_sale = $request->state_sale;
        $type_payment = $request->type_payment;
        $start_date = $request->start_date;
        $end_date = $request->end_date;

        $sales = Sale::filterMultiple($search_product, $categorie_id, $search, $search_client, $state_sale, $type_payment, $start_date, $end_date)
                        ->orderBy("id", "desc")
                        ->paginate(25);

        return response()->json([
            "total" => $sales->count(),
            "paginate" => 25,
            "sales" => SaleCollection::make($sales),
        ]);
    }

    public function config()
    {
        $today = today();
        $clients = Client::where("state", 1)->orderBy("full_name", "asc")->get();
        $products = Product::where("state", 1)->where("is_especial_nota", 0)->orderBy("title", "asc")->get();

        $n_transaction = 1000;
        $sale = Sale::orderBy("id", "desc")->first();
        if ($sale) { //si sale no es nulo entonces se obtiene el correlativo y se le suma 1
            $n_transaction = $sale->n_transaction + 1;
        }

        return response()->json([
            "clients" => ClientCollection::make($clients),
            "products" => ProductCollection::make($products),
            "today" => $today->format("Y-m-d"),
            "n_transaction" => str_pad($n_transaction, 8, "0", STR_PAD_LEFT), //correlativo 00001
        ]);
    }
    public function pdf($id){
        $sale = Sale::find($id);
        if(!$sale){
           // throw new HttpException(404, "Venta no encontrada");
           return abort(404);
        }
        $pdf = Pdf::loadView('pdf_sale', compact('sale'));//el loadView es para cargar la vista
        return $pdf->stream('sale_'.$id.'.pdf');//el stream es para descargar el pdf

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $sale_details = $request->sale_details;
        $sale_payments = $request->payments;

        try {
            DB::beginTransaction();

            $sale = Sale::create([
                "date" => $request->date,
                "serie" => $request->serie,
                "user_id" => auth('api')->user()->id,
                "client_id" => $request->client_id,
                "type_client" => $request->type_client,

                "n_transaccion" => $request->n_transaccion,
                "subtotal" => $request->subtotal,
                "total" => $request->total,
                "igv" => $request->igv,
                "state_sale" => $request->state_sale, //venta o cotizacion
                "state_payment" => $request->state_payment, //pagado o pendiente
                "type_payment" => $request->type_payment, //a contado o credito
                "debt" => $request->debt, //deuda
                "paid_out" => $request->paid_out, //monto pagado
                "description" => $request->description, //descripcion
                "discount" => $request->discount, //descuento por item
                "retencion_igv" => $request->retencion_igv, //retencion igv
                "discount_global" => $request->discount_global, //descuento global de la venta
                "igv_discount_general" => $request->igv_discount_general,

                //n_comprobante_anticipo,
                //amount_anticipo,
                //sales_anticipos

                "is_exportacion" => $request->is_exportacion,
                "currency" => $request->currency,
            ]);

            foreach ($sale_details as $key => $sale_detail) {
                SaleDetail::create([
                    "sale_id" => $sale->id,
                    "product_id" => $sale_detail["product"]["id"],
                    "product_categorie_id" => $sale_detail["product"]["categorie_id"],
                    "unidad_medida" => $sale_detail["unidad_medida"],
                    "quantity" => $sale_detail["quantity"],
                    "price_base" => $sale_detail["price_base"],
                    "price_final" => $sale_detail["price_final"],
                    "discount" => $sale_detail["discount"],
                    "subtotal" => $sale_detail["subtotal"],
                    "igv" => $sale_detail["igv"],
                    //"description" => $sale_detail["description"],
                    "tip_afe_igv" => $sale_detail["tip_afe_igv"],
                    "per_icbper" => $sale_detail["per_icbper"],
                    "icbper" => $sale_detail["icbper"],
                    "percentage_isc" => $sale_detail["percentage_isc"],
                    "isc" => $sale_detail["isc"],
                ]);
            }

            foreach ($sale_payments as $key => $sale_payment) {
                SalePayment::create([
                    "sale_id" => $sale->id,
                    "method_payment" => $sale_payment["method_payment"],
                    "amount" => $sale_payment["amount"],
                    "date_payment" => $sale_payment["date_payment"],
                ]);
            }

            //actualizar el stock
            foreach ($sale_details as $key => $sale_detail) {
                $product = Product::find($sale_detail["product"]["id"]);
                $product->decrement('stock', $sale_detail["quantity"]);// la funcion decrement se utiliza para disminuir el stock es propia de laravel
                $product->save();
            }

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw new HttpException(500, $th->getMessage());
        }

        return response()->json([
            "code" => 200,
            "message" => "Venta creada exitosamente",
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $sale = Sale::find($id);
        if(!$sale){
           // throw new HttpException(404, "Venta no encontrada");
           return response()->json([
               "code" => 405,
               "message" => "Venta no encontrada"
           ]);
        }
        return response()->json([
            "sale" => SaleResource::make($sale),
            "code" => 200
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $sale = Sale::find($id);
        $sale->update($request->all());

        return response()->json([
           "code" => 200,
            "message" => "Venta Editada correctamente"
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $sale = Sale::find($id);
        $sale->delete();
        return response()->json([
            "code" => 200,
            "message" => "Venta Eliminada correctamente"
        ]);
    }
}
