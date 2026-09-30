<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\TipoMovimientoSaldoFavor;
use App\Models\Creditos\CreditoSaldoFavorMovimiento;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    /**
     * Deshace los movimientos que generó un pago al anularlo (1.8). Si el excedente que dejó como
     * saldo a favor ya se usó en otro pago, el saldo quedaría negativo: primero se anula ese uso.
     */
    public function revertirPago(int $pagoId, User $usuario): void
    {
        $movimientos = CreditoSaldoFavorMovimiento::where('pago_id', $pagoId)
            ->whereIn('tipo', [TipoMovimientoSaldoFavor::Abono, TipoMovimientoSaldoFavor::Uso])
            ->get();

        foreach ($movimientos as $m) {
            $reverso = -Dinero::aCentavos($m->monto);
            if ($reverso < 0 && $this->saldo($m->cliente_id) + $reverso < 0) {
                throw new HttpException(422, 'El saldo a favor que dejó este pago ya se usó en otro cobro. Anula primero ese cobro.');
            }
            $this->registrar($m->cliente_id, TipoMovimientoSaldoFavor::Reverso, $reverso, $pagoId, 'Anulación del pago', $usuario);
        }
    }
}
