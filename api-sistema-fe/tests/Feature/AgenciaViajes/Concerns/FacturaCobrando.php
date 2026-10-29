<?php

namespace Tests\Feature\AgenciaViajes\Concerns;

use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Models\Cash\CashRegister;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * T-011: facturar una reserva al contado exige registrar el cobro. Los tests que solo quieren una
 * venta emitida llaman a facturarCobrando(): arma el cobro en efectivo por el saldo (con caja
 * abierta del usuario) y llama a store() del mismo modo que antes.
 */
trait FacturaCobrando
{
    private function facturarCobrando(Request $pedido, string $reservaId)
    {
        $controller = app(ReservaFacturacionController::class);
        $datos = $pedido->all();

        if (($datos['condicion_pago'] ?? 'contado') === 'credito' || ! empty($datos['pagos'])) {
            return $controller->store($pedido, $reservaId);
        }

        try {
            $preview = $controller->prepararFactura(new Request($datos), $reservaId);
        } catch (HttpException) {
            return $controller->store($pedido, $reservaId);
        }
        $cuerpo = $preview->getData(true);
        if ($preview->getStatusCode() !== 200 || empty($cuerpo['saldo_a_cobrar']) || $cuerpo['saldo_a_cobrar'] <= 0) {
            return $controller->store($pedido, $reservaId);
        }

        $usuario = auth('api')->user();
        if (! CashSession::where('opened_by', $usuario->id)->where('status', 'open')->exists()) {
            $caja = CashRegister::create(['branch_id' => $usuario->branch_id, 'name' => 'Caja T011 ' . uniqid(), 'is_active' => true]);
            CashSession::create([
                'cash_register_id' => $caja->id, 'opened_by' => $usuario->id,
                'opening_amount' => 0, 'opened_at' => now(), 'status' => 'open',
            ]);
        }
        PaymentMethod::firstOrCreate(
            ['code' => 'EFECTIVO'],
            ['name' => 'Efectivo', 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => true]
        );

        $datos['pagos'] = [[
            'method_payment' => 'EFECTIVO',
            'moneda_pago' => $cuerpo['moneda_facturacion'],
            'monto_pagado' => $cuerpo['saldo_a_cobrar'],
        ]];

        return $controller->store(new Request($datos), $reservaId);
    }
}
