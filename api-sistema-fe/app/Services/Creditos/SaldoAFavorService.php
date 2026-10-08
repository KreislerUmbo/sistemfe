<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\TipoMovimientoSaldoFavor;
use App\Models\Cash\CashMovement;
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
        $movimientos = CreditoSaldoFavorMovimiento::where('cliente_id', $clienteId)
            ->with('pago:id,numero_recibo')
            ->orderByDesc('id')
            ->get();
        // Devoluciones cuya salida de caja ya se revirtió (anuladas).
        $anuladas = CashMovement::whereIn('id', $movimientos->pluck('cash_movement_id')->filter())
            ->whereNotNull('corrected_by')->pluck('id')->all();

        return $movimientos
            ->map(static fn (CreditoSaldoFavorMovimiento $m): array => [
                'id' => $m->id,
                'tipo' => $m->tipo->value,
                'monto' => (string) $m->monto,
                'numero_recibo' => $m->pago?->numero_recibo,
                'motivo' => $m->motivo,
                'fecha' => \App\Services\HoraPeru::deUtc($m->created_at)?->format('Y-m-d H:i'),
                'anulada' => $m->tipo === TipoMovimientoSaldoFavor::Devolucion && in_array($m->cash_movement_id, $anuladas, true),
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
     * Anula una devolución mal registrada (revisión 08-oct-2026): el dinero vuelve a la caja y el
     * saldo, al cliente. Mismo criterio que anular un pago (1.14): la propia con la caja todavía
     * abierta; cualquier otra, creditos.anular_pago.
     */
    public function anularDevolucion(Client $cliente, int $movimientoId, string $motivo, User $usuario): CreditoSaldoFavorMovimiento
    {
        return DB::transaction(function () use ($cliente, $movimientoId, $motivo, $usuario): CreditoSaldoFavorMovimiento {
            $this->bloquear($cliente->id);
            $devolucion = CreditoSaldoFavorMovimiento::where('cliente_id', $cliente->id)
                ->where('tipo', TipoMovimientoSaldoFavor::Devolucion)->findOrFail($movimientoId);
            $salida = $devolucion->cash_movement_id === null ? null : CashMovement::with('cashSession')->find($devolucion->cash_movement_id);
            if ($salida === null || $salida->corrected_by !== null) {
                throw new HttpException(422, 'Esta devolución ya está anulada.');
            }
            $propiaConCajaAbierta = $devolucion->registrado_por === $usuario->id
                && $salida->cashSession->status === 'open' && $salida->cashSession->opened_by === $usuario->id;
            if (! $propiaConCajaAbierta && ! $usuario->can('creditos.anular_pago')) {
                throw new HttpException(403, 'Solo puedes anular tus propias devoluciones mientras tu caja siga abierta. Para otros casos se requiere creditos.anular_pago.');
            }

            $this->caja->revertirReferencia(CajaCredito::DEVOLUCION_SALDO_FAVOR, $devolucion->id, $usuario);
            $centavos = -Dinero::aCentavos((string) $devolucion->monto);
            $reverso = $this->registrar($cliente->id, TipoMovimientoSaldoFavor::Reverso, $centavos, null, "Anulación de la devolución: {$motivo}", $usuario);
            $this->auditoria->registrar('saldo_favor.anular_devolucion', $cliente, null, ['devolucion_id' => $devolucion->id],
                ['saldo' => Dinero::aSoles($this->saldo($cliente->id)), 'repuesto' => Dinero::aSoles($centavos)], $motivo, $usuario);

            return $reverso;
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
