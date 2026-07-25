<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Models\Sale\Sale;
use App\Models\Sale\SalePayment;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        // ── Blindaje: no aceptar un pago que exceda la deuda pendiente ────
        // Este endpoint se usa desde sale/edit.vue para agregar pagos uno a
        // uno a una venta ya guardada (a diferencia de SaleController::store(),
        // que recibe todos los pagos juntos al crear la venta). No tenía
        // ningún candado — permitía dejar `debt` negativo sin aviso.
        $sale = Sale::find($request->sale_id);

        if (!$sale) {
            throw new HttpException(404, 'Venta no encontrada.');
        }

        // Módulo Amortizaciones — plan-modulo-amortizaciones.md §9. Una
        // venta condicion_pago='credito' se cobra EXCLUSIVAMENTE vía
        // CreditPaymentController (Fase 4): si este flujo legado la
        // tocara, movería saldo_pendiente por fuera de payment_applications,
        // rompiendo el FIFO y dejando installments/payment_receipts sin
        // rastro del movimiento real de dinero.
        if ($sale->condicion_pago === 'credito') {
            throw new HttpException(
                422,
                "La venta #{$sale->id} es a crédito (condicion_pago='credito') — los pagos " .
                "se registran exclusivamente por el módulo de Amortizaciones (Cuentas por " .
                "Cobrar), no por este flujo."
            );
        }

        if ((float) $request->amount <= 0) {
            throw new HttpException(422, 'El monto del pago debe ser mayor a 0.');
        }

        if (round((float) $request->amount, 2) > round((float) $sale->debt, 2)) {
            throw new HttpException(
                422,
                "El pago (S/ " . number_format((float) $request->amount, 2) . ") " .
                "supera la deuda pendiente de la venta (S/ " . number_format((float) $sale->debt, 2) . ")."
            );
        }

        $sale_payment = SalePayment::create([
            "sale_id" => $request->sale_id,
            "method_payment" => $request->method_payment,
            "amount" => $request->amount,
            "date_payment" => $request->date_payment,
        ]);

        // Módulo Amortizaciones (§9) — espejo simétrico de 'debt', mismo
        // criterio de centavos enteros que el resto del módulo (sin floats).
        $montoCentavos = (int) round((float) $sale_payment->amount * 100);
        $saldoPendienteCentavos = (int) round((float) $sale->saldo_pendiente * 100) - $montoCentavos;

        $sale->update([
            "paid_out" => $sale->paid_out + $sale_payment->amount, //monto pagado + el nuevo monto
            "debt" => $sale->debt - $sale_payment->amount, //deuda - el nuevo monto
            "saldo_pendiente" => round($saldoPendienteCentavos / 100, 2),
        ]);

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
        $sale = Sale::find($sale_payent->sale_id); //buscamos el id de la venta para depues actualizar los montos de la deuda y el monto pagado

        // Módulo Amortizaciones (§9) — mismo resguardo que store(): en
        // condiciones normales una venta a crédito no debería tener
        // sale_payments (bloqueado arriba), pero si existiera una fila de
        // antes de este fix, tampoco dejamos que destroy() mueva
        // saldo_pendiente por fuera del módulo de pagos nuevo.
        if ($sale && $sale->condicion_pago === 'credito') {
            throw new HttpException(
                422,
                "La venta #{$sale->id} es a crédito — este pago no se puede eliminar por " .
                "este flujo legado, usa la anulación del módulo de Amortizaciones."
            );
        }

        $sale_payent->delete();

        // Módulo Amortizaciones (§9) — espejo simétrico de 'debt', mismo
        // criterio de centavos enteros que el resto del módulo.
        $montoCentavos = (int) round((float) $sale_payent->amount * 100);
        $saldoPendienteCentavos = (int) round((float) $sale->saldo_pendiente * 100) + $montoCentavos;

        $sale->update([
            "debt" => $sale->debt + $sale_payent->amount, //deuda es igual a la deuda mas el nuevo monto
            "paid_out" => $sale->paid_out - $sale_payent->amount, //monto pagado es igual al monto pagado menos el nuevo monto
            "saldo_pendiente" => round($saldoPendienteCentavos / 100, 2),
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
