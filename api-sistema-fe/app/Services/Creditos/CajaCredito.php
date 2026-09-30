<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Client\Client;
use App\Models\User;
use App\Services\CashCorrectionService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Movimientos de caja del módulo (00 §3). Mismo patrón que Amortizaciones/Ventas: la
 * sesión abierta del usuario, un movimiento por operación y reversos solo vía
 * CashCorrectionService (nunca se edita ni borra un movimiento).
 */
class CajaCredito
{
    public const DESEMBOLSO = 'credito_desembolso';
    public const PAGO = 'credito_pago';
    public const DEVOLUCION_EXCEDENTE = 'credito_devolucion_excedente';

    private const ENTRADA = 'in';
    private const SALIDA = 'out';

    public function __construct(private readonly CashCorrectionService $correccion)
    {
    }

    public function sesionAbierta(User $usuario): CashSession
    {
        $sesion = CashSession::where('opened_by', $usuario->id)->where('status', 'open')->first();
        if ($sesion === null) {
            throw new HttpException(422, 'No hay una sesión de caja abierta. Abre caja antes de registrar esta operación.');
        }

        return $sesion;
    }

    public function entrada(CashSession $sesion, User $usuario, string $tipo, int $referenciaId, int $centavos, int $paymentMethodId, Client $cliente, string $descripcion): CashMovement
    {
        return $this->registrar($sesion, $usuario, $tipo, $referenciaId, self::ENTRADA, $centavos, $paymentMethodId, $cliente, $descripcion);
    }

    public function salida(CashSession $sesion, User $usuario, string $tipo, int $referenciaId, int $centavos, int $paymentMethodId, Client $cliente, string $descripcion): CashMovement
    {
        return $this->registrar($sesion, $usuario, $tipo, $referenciaId, self::SALIDA, $centavos, $paymentMethodId, $cliente, $descripcion);
    }

    /** Revierte (en la caja abierta de quien anula) todos los movimientos vigentes de una referencia. */
    public function revertirReferencia(string $tipo, int $referenciaId, User $usuario): void
    {
        CashMovement::where('reference_type', $tipo)
            ->where('reference_id', $referenciaId)
            ->where('type', $tipo)
            ->whereNull('corrected_by')
            ->get()
            ->each(fn (CashMovement $m) => $this->correccion->revertirMovimiento($m, $usuario));
    }

    private function registrar(
        CashSession $sesion,
        User $usuario,
        string $tipo,
        int $referenciaId,
        string $direccion,
        int $centavos,
        int $paymentMethodId,
        Client $cliente,
        string $descripcion,
    ): CashMovement {
        return CashMovement::create([
            'cash_session_id' => $sesion->id,
            'type' => $tipo,
            'payment_method_id' => $paymentMethodId,
            'direction' => $direccion,
            'amount' => Dinero::aSoles($centavos),
            'reference_type' => $tipo,
            'reference_id' => $referenciaId,
            'description' => $descripcion,
            'counterparty_type' => 'cliente',
            'counterparty_id' => $cliente->id,
            'counterparty_name' => $cliente->full_name,
            'counterparty_document' => $cliente->n_document,
            'status' => 'confirmed',
            'created_by' => $usuario->id,
        ]);
    }
}
