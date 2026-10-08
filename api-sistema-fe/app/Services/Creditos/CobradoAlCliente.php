<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cuánto de un pago lo pagó el cliente con su dinero (Panel, Agenda, conciliación). Cobros y
 * liquidaciones: todo lo aplicado. Renovación (1.21 ampliada): solo la diferencia que pagó en caja
 * (liquidación cubierta − capital del crédito nuevo); la parte que cubre el crédito nuevo no es cobro.
 */
final class CobradoAlCliente
{
    /** Pagos que llevan dinero del cliente: cobros, liquidaciones y renovaciones con pago en caja. */
    public static function filtrar(Builder $consulta): Builder
    {
        return $consulta->where(static fn (Builder $q) => $q
            ->whereIn('origen', [OrigenPago::Cobro, OrigenPago::Liquidacion])
            ->orWhere(static fn (Builder $r) => $r->where('origen', OrigenPago::Renovacion)->whereNotNull('payment_method_id')));
    }

    /** Centavos pagados por el cliente en este pago. */
    public static function centavos(CreditoPago $pago): int
    {
        $aplicado = Dinero::aCentavos((string) $pago->monto_aplicado);
        if ($pago->origen !== OrigenPago::Renovacion) {
            return $aplicado;
        }
        if ($pago->payment_method_id === null) {
            return 0;
        }
        $capitalNuevo = Credito::where('credito_renovado_id', $pago->credito_id)
            ->where('estado', '!=', CreditoEstado::Anulado)->latest('id')->value('monto_capital');

        return max(0, $aplicado - Dinero::aCentavos((string) ($capitalNuevo ?? '0')));
    }
}
