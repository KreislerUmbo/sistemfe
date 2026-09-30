<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\TipoMovimientoSaldoFavor;
use App\Models\Creditos\CreditoSaldoFavorMovimiento;
use App\Models\User;

/** Saldo a favor del cliente en créditos (00 1.4): el saldo es la suma de sus movimientos. */
class SaldoAFavorService
{
    public function saldo(int $clienteId): int
    {
        return Dinero::aCentavos((string) (CreditoSaldoFavorMovimiento::where('cliente_id', $clienteId)->sum('monto') ?: '0'));
    }

    /** @param int $centavos positivo suma al saldo, negativo lo resta */
    public function registrar(int $clienteId, TipoMovimientoSaldoFavor $tipo, int $centavos, ?int $pagoId, string $motivo, User $usuario): void
    {
        CreditoSaldoFavorMovimiento::create([
            'cliente_id' => $clienteId,
            'tipo' => $tipo,
            'monto' => Dinero::aSoles($centavos),
            'pago_id' => $pagoId,
            'motivo' => $motivo,
            'registrado_por' => $usuario->id,
        ]);
    }

    /** Deshace los movimientos que generó un pago al anularlo (1.8). */
    public function revertirPago(int $pagoId, User $usuario): void
    {
        CreditoSaldoFavorMovimiento::where('pago_id', $pagoId)
            ->whereIn('tipo', [TipoMovimientoSaldoFavor::Abono, TipoMovimientoSaldoFavor::Uso])
            ->get()
            ->each(fn (CreditoSaldoFavorMovimiento $m) => $this->registrar(
                $m->cliente_id, TipoMovimientoSaldoFavor::Reverso, -Dinero::aCentavos($m->monto), $pagoId, 'Anulación del pago', $usuario,
            ));
    }
}
