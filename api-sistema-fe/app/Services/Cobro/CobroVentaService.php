<?php

namespace App\Services\Cobro;

use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Sale\Sale;
use App\Models\Sale\SalePayment;
use App\Models\User;
use App\Services\HoraPeru;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * T-010 — regla única de cobro de una venta (Ventas hoy, Agencia en T-011): valida los pagos
 * (método, moneda, tipo de cambio), los compara con el saldo (con vuelto si corresponde) y
 * los registra junto con sus movimientos de caja.
 *
 * Tipo de cambio = soles por dólar, siempre. Venta en soles pagada en dólares: equivalente =
 * monto × tc; venta en dólares pagada en soles: monto ÷ tc. Es el único punto de conversión
 * y de redondeo; las comparaciones se hacen en centavos.
 */
class CobroVentaService
{
    private const TIPO_CAMBIO_MAXIMO = 20;

    /**
     * Antes de la transacción. Cada pago de entrada: method_payment (code), amount | monto_pagado,
     * moneda_pago? (default: la de la venta), tipo_cambio? (obligatorio si la moneda difiere),
     * nro_operacion?, date_payment? (default hoy en Perú).
     *
     * @return list<array{method_payment: string, payment_method_id: int, afecta_efectivo: bool, moneda_pago: string, monto_pagado: float, tipo_cambio: float, amount: float, nro_operacion: ?string, date_payment: string}>
     */
    public function prepararPagos(array $pagos, string $monedaVenta): array
    {
        $monedaVenta = CashMovement::monedaOFalla($monedaVenta);
        $preparados = [];

        foreach ($pagos as $pago) {
            $codigo = trim((string) ($pago['method_payment'] ?? ''));
            $metodo = PaymentMethod::whereRaw('LOWER(code) = ?', [strtolower($codigo)])->where('is_active', true)->first();
            if (! $metodo) {
                throw new HttpException(422, "Método de pago no válido o inactivo: \"{$codigo}\".");
            }

            $moneda = CashMovement::normalizarMoneda($pago['moneda_pago'] ?? $monedaVenta);
            if ($moneda === null) {
                throw new HttpException(422, 'La moneda del pago debe ser PEN o USD.');
            }
            if (! in_array($moneda, $metodo->monedas ?? ['PEN', 'USD'], true)) {
                throw new HttpException(422, "{$metodo->name} no acepta pagos en ".($moneda === 'USD' ? 'dólares' : 'soles').'.');
            }

            $monto = $pago['monto_pagado'] ?? $pago['amount'] ?? null;
            if (! is_numeric($monto) || round((float) $monto, 2) <= 0) {
                throw new HttpException(422, 'Cada pago requiere un monto mayor a 0.');
            }
            $montoPagado = round((float) $monto, 2);

            if ($moneda === $monedaVenta) {
                $tipoCambio = 1.0;
                $equivalente = $montoPagado;
            } else {
                $tipoCambio = $pago['tipo_cambio'] ?? null;
                if (! is_numeric($tipoCambio) || (float) $tipoCambio <= 0 || (float) $tipoCambio > self::TIPO_CAMBIO_MAXIMO) {
                    throw new HttpException(422, 'Un pago en otra moneda necesita un tipo de cambio mayor a 0 y hasta '.self::TIPO_CAMBIO_MAXIMO.'.');
                }
                $tipoCambio = (float) $tipoCambio;
                $equivalente = $monedaVenta === 'PEN'
                    ? round($montoPagado * $tipoCambio, 2)
                    : round($montoPagado / $tipoCambio, 2);
            }

            $nroOperacion = isset($pago['nro_operacion']) ? trim((string) $pago['nro_operacion']) : '';
            if (mb_strlen($nroOperacion) > 50) {
                throw new HttpException(422, 'El n.º de operación no puede pasar de 50 caracteres.');
            }

            $preparados[] = [
                'method_payment' => $codigo,
                'payment_method_id' => $metodo->id,
                'afecta_efectivo' => (bool) $metodo->affects_cash_count,
                'moneda_pago' => $moneda,
                'monto_pagado' => $montoPagado,
                'tipo_cambio' => $tipoCambio,
                'amount' => $equivalente,
                'nro_operacion' => $nroOperacion === '' ? null : $nroOperacion,
                'date_payment' => $pago['date_payment'] ?? HoraPeru::hoyTexto(),
            ];
        }

        return $preparados;
    }

    /**
     * exacto=false (Ventas retail): puede faltar; pasarse → 422.
     * exacto=true (Agencia, contado): tiene que cubrir el saldo; el exceso solo se acepta como
     * vuelto si hay efectivo y no supera lo pagado en efectivo (en equivalente).
     *
     * @return array{monto: float, moneda: string}|null
     */
    public function validarContraSaldo(array $pagosPreparados, float $saldo, string $monedaVenta, bool $exacto, ?string $monedaVuelto = null): ?array
    {
        $monedaVenta = CashMovement::monedaOFalla($monedaVenta);
        $cobrado = $this->centavos(array_sum(array_column($pagosPreparados, 'amount')));
        $saldoCent = $this->centavos($saldo);
        $simbolo = $monedaVenta === 'USD' ? 'US$' : 'S/';

        if ($cobrado < $saldoCent) {
            if ($exacto) {
                throw new HttpException(422, "Lo cobrado ({$simbolo} ".number_format($cobrado / 100, 2).") no cubre el total a pagar ({$simbolo} ".number_format($saldoCent / 100, 2).').');
            }

            return null;
        }
        if ($cobrado === $saldoCent) {
            return null;
        }

        if (! $exacto) {
            throw new HttpException(
                422,
                "La suma de pagos ({$simbolo} ".number_format($cobrado / 100, 2).') '.
                "supera el total a pagar de la venta ({$simbolo} ".number_format($saldoCent / 100, 2).').'
            );
        }

        $exceso = $cobrado - $saldoCent;
        $efectivo = array_filter($pagosPreparados, fn ($p) => $p['afecta_efectivo']);
        $efectivoCent = $this->centavos(array_sum(array_column($efectivo, 'amount')));
        if (! $efectivo || $exceso > $efectivoCent) {
            throw new HttpException(422, 'El pago supera el total a pagar y el exceso no se puede devolver como vuelto: solo se da vuelto de lo pagado en efectivo.');
        }

        $monedaVuelto = $monedaVuelto === null ? $monedaVenta : CashMovement::normalizarMoneda($monedaVuelto);
        if ($monedaVuelto === null) {
            throw new HttpException(422, 'La moneda del vuelto debe ser PEN o USD.');
        }
        if ($monedaVuelto === $monedaVenta) {
            return ['monto' => $exceso / 100, 'moneda' => $monedaVuelto];
        }

        // Vuelto en la otra moneda: con el tipo de cambio del pago en efectivo en esa moneda.
        $tipoCambio = null;
        foreach ($efectivo as $pago) {
            if ($pago['moneda_pago'] === $monedaVuelto) {
                $tipoCambio = $pago['tipo_cambio'];
                break;
            }
        }
        if ($tipoCambio === null) {
            throw new HttpException(422, 'Para dar el vuelto en esa moneda hace falta un pago en efectivo en esa misma moneda (de ahí sale el tipo de cambio).');
        }
        $monto = $monedaVenta === 'PEN' ? ($exceso / 100) / $tipoCambio : ($exceso / 100) * $tipoCambio;

        return ['monto' => round($monto, 2), 'moneda' => $monedaVuelto];
    }

    public function sesionAbierta(User $usuario): CashSession
    {
        $sesion = CashSession::where('opened_by', $usuario->id)->where('status', 'open')->first();
        if (! $sesion) {
            throw new HttpException(422, 'Abre tu caja para cobrar.');
        }

        return $sesion;
    }

    /**
     * Dentro de la transacción: los pagos de la venta, sus movimientos de caja y, si hay, el vuelto.
     */
    public function registrar(Sale $venta, array $pagosPreparados, CashSession $sesion, User $usuario, ?array $vuelto = null): void
    {
        $this->guardarPagos($venta, $pagosPreparados);
        $this->crearMovimientos($venta, $pagosPreparados, $sesion, $usuario);

        if ($vuelto) {
            $efectivo = PaymentMethod::whereRaw('LOWER(code) = ?', ['efectivo'])->first();
            if (! $efectivo) {
                throw new HttpException(500, 'No se encontró el método de pago EFECTIVO para registrar el vuelto.');
            }
            $moneda = CashMovement::monedaOFalla($vuelto['moneda']);
            $venta->update(['vuelto_monto' => $vuelto['monto'], 'vuelto_moneda' => $moneda]);
            CashMovement::create([
                'cash_session_id' => $sesion->id,
                'type' => 'sale_change',
                'payment_method_id' => $efectivo->id,
                'direction' => 'out',
                'amount' => $vuelto['monto'],
                'currency' => $moneda,
                'reference_type' => 'sale',
                'reference_id' => $venta->id,
                'status' => 'confirmed',
                'created_by' => $usuario->id,
            ]);
        }
    }

    /** Solo las filas de sale_payments (update() de Ventas las rehace aunque la caja no cambie). */
    public function guardarPagos(Sale $venta, array $pagosPreparados): void
    {
        foreach ($pagosPreparados as $pago) {
            SalePayment::create([
                'sale_id' => $venta->id,
                'method_payment' => $pago['method_payment'],
                'amount' => $pago['amount'],
                'date_payment' => $pago['date_payment'],
                'nro_operacion' => $pago['nro_operacion'],
                'moneda_pago' => $pago['moneda_pago'],
                'monto_pagado' => $pago['monto_pagado'],
                'tipo_cambio' => $pago['tipo_cambio'],
            ]);
        }
    }

    /** Un cash_movement 'sale_payment' por pago, en la moneda y monto del pago. */
    public function crearMovimientos(Sale $venta, array $pagosPreparados, CashSession $sesion, User $usuario): void
    {
        foreach ($pagosPreparados as $pago) {
            CashMovement::create([
                'cash_session_id' => $sesion->id,
                'type' => 'sale_payment',
                'payment_method_id' => $pago['payment_method_id'],
                'direction' => 'in',
                'amount' => $pago['monto_pagado'],
                'currency' => $pago['moneda_pago'],
                'reference_type' => 'sale',
                'reference_id' => $venta->id,
                'status' => 'confirmed',
                'created_by' => $usuario->id,
            ]);
        }
    }

    private function centavos(float|int $monto): int
    {
        return (int) round($monto * 100);
    }
}
