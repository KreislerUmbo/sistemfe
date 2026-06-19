<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Models\Sale\Sale;
use App\Models\Sale\SalePayment;
use Illuminate\Http\Request;

class SalePaymentController extends Controller
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
        $sale_payment = SalePayment::create([
            "sale_id" => $request->sale_id,
            "method_payment" => $request->method_payment,
            "amount" => $request->amount,
            "date_payment" => $request->date_payment,
        ]);

        $sale = Sale::find($request->sale_id);
        $sale->update([
            "paid_out" => $sale->paid_out + $sale_payment->amount, //monto pagado + el nuevo monto
            "debt" => $sale->debt - $sale_payment->amount //deuda - el nuevo monto
        ]);

        $state_payment = 1;
        if ($sale->debt <= 0) {
            $sale->update([
                "state_payment" => 3,
            ]);
        }

        return response()->json([
            "payment" => [
                "id" => $sale_payment->id,
                "sale_id" => $sale_payment->sale_id,
                "method_payment" => $sale_payment->method_payment,
                "amount" => $sale_payment->amount,
                "date_payment" => $sale_payment->date_payment
            ],
            "code" => 200,
            "message" => "Pago registrado correctamente"
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
        $sale_payent = SalePayment::find($id);
        $sale_payent->delete();

        $sale = Sale::find($sale_payent->sale_id); //buscamos el id de la venta para depues actualizar los montos de la deuda y el monto pagado
        $sale->update([
            "debt" => $sale->debt + $sale_payent->amount, //deuda es igual a la deuda mas el nuevo monto   
            "paid_out" => $sale->paid_out - $sale_payent->amount, //monto pagado es igual al monto pagado menos el nuevo monto

        ]);

        $state_payment = 1; //1=pendiente, 2=parcial, 3=completo
        if ($sale->paid_out <= 0) {
            $state_payment = 2;
        } else if ($sale->debt <= 0) {
            $state_payment = 3;
        }
        $sale->update([
            "state_payment" => $state_payment,
        ]);

        return response()->json([
            "code" => 200,
            "message" => "Pago eliminado correctamente"
        ]);
    }
}
