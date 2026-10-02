<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\TipoMovimientoSaldoFavor;
use App\Models\Client\Client;
use App\Models\Creditos\CreditoSaldoFavorMovimiento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Saldo a favor del cliente en créditos (00 1.4): el saldo es la suma de sus movimientos.
 * Quien lo consume (cobro con saldo, devolución, reverso al anular) bloquea antes la fila del
 * cliente: dos operaciones simultáneas sobre créditos distintos del mismo cliente no pueden
 * gastar el mismo saldo dos veces (04c.1). Orden de bloqueo: crédito → cliente.
 */
class SaldoAFavorService
{
    public function __construct(
        private readonly CajaCredito $caja,
        private readonly AuditoriaCredito $auditoria,
    ) {
    }

    public function saldo(int $clienteId): int
    {
        return Dinero::aCentavos((string) (CreditoSaldoFavorMovimiento::where('cliente_id', $clienteId)->sum('monto') ?: '0'));
    }

    /** Bloquea la fila del cliente hasta el fin de la transacción en curso. */
    public function bloquear(int $clienteId): void
    {
        Client::whereKey($clienteId)->lockForUpdate()->first();
    }

    /** @return list<array<string, mixed>> movimientos del cliente, el más reciente primero */
    public function movimientos(int $clienteId): array
    {
        return CreditoSaldoFavorMovimiento::where('cliente_id', $clienteId)
            ->with('pago:id,numero_recibo')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (CreditoSaldoFavorMovimiento $m): array => [
                'id' => $m->id,
                'tipo' => $m->tipo->value,
                'monto' => (string) $m->monto,
                'numero_recibo' => $m->pago?->numero_recibo,
                'motivo' => $m->motivo,
                'fecha' => $m->created_at?->format('Y-m-d H:i'),
            ])->all();
    }

    /** @param int $centavos positivo suma al saldo, negativo lo resta */
    public function registrar(int $clienteId, TipoMovimientoSaldoFavor $tipo, int $centavos, ?int $pagoId, string $motivo, User $usuario): CreditoSaldoFavorMovimiento
    {
        return CreditoSaldoFavorMovimiento::create([
            'cliente_id' => $clienteId,
            'tipo' => $tipo,
            'monto' => Dinero::aSoles($centavos),
            'pago_id' => $pagoId,
            'motivo' => $motivo,
            'registrado_por' => $usuario->id,
        ]);
    }

    /**
     * Entrega en efectivo (o por el método elegido) parte o todo el saldo a favor: salida de la
     * caja abierta de quien devuelve, con su movimiento de saldo enlazado (04c.1).
     */
    public function devolver(Client $cliente, int $centavos, int $paymentMethodId, string $motivo, User $usuario): CreditoSaldoFavorMovimiento
    {
        if ($centavos <= 0) {
            throw new HttpException(422, 'Indica un monto mayor a cero.');
        }

        return DB::transaction(function () use ($cliente, $centavos, $paymentMethodId, $motivo, $usuario): CreditoSaldoFavorMovimiento {
            $this->bloquear($cliente->id);
            $disponible = $this->saldo($cliente->id);
            if ($centavos > $disponible) {
                throw new HttpException(422, 'El monto supera el saldo a favor del cliente (' . Dinero::aSoles($disponible) . ').');
            }
            $sesion = $this->caja->sesionAbierta($usuario);

            $movimiento = $this->registrar($cliente->id, TipoMovimientoSaldoFavor::Devolucion, -$centavos, null, $motivo, $usuario);
            $salida = $this->caja->salida(
                $sesion, $usuario, CajaCredito::DEVOLUCION_SALDO_FAVOR, $movimiento->id, $centavos, $paymentMethodId, $cliente,
                'Devolución de saldo a favor',
            );
            $movimiento->update(['cash_movement_id' => $salida->id]);
            $this->auditoria->registrar('saldo_favor.devolver', $cliente, null, ['saldo' => Dinero::aSoles($disponible)],
                ['saldo' => Dinero::aSoles($disponible - $centavos), 'devuelto' => Dinero::aSoles($centavos)], $motivo, $usuario);

            return $movimiento;
        });
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
            $this->bloquear($m->cliente_id);
            $reverso = -Dinero::aCentavos($m->monto);
            if ($reverso < 0 && $this->saldo($m->cliente_id) + $reverso < 0) {
                throw new HttpException(422, 'El saldo a favor que dejó este pago ya se usó en otro cobro o se devolvió. Anula primero ese cobro.');
            }
            $this->registrar($m->cliente_id, TipoMovimientoSaldoFavor::Reverso, $reverso, $pagoId, 'Anulación del pago', $usuario);
        }
    }
}
