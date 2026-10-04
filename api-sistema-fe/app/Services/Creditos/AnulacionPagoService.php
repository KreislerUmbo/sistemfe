<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\PagoEstado;
use App\Models\Cash\CashMovement;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Models\Creditos\CreditoReprogramacion;
use App\Models\User;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Extorno con recálculo (00 1.8): el pago pasa a anulado, se reaplican todos los válidos,
 * se revierte su caja y su saldo a favor. Nunca se edita ni borra un pago.
 */
class AnulacionPagoService
{
    private const PERMISO_ANULAR_CUALQUIERA = 'creditos.anular_pago';

    public function __construct(
        private readonly CargadorCredito $cargador,
        private readonly PersistidorReparto $persistidor,
        private readonly AplicadorPagos $aplicador,
        private readonly CajaCredito $caja,
        private readonly SaldoAFavorService $saldos,
        private readonly AlcanceCartera $alcance,
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    public function anular(CreditoPago $pago, string $motivo, User $usuario): CreditoPago
    {
        return DB::transaction(fn (): CreditoPago => $this->ejecutar($pago, $motivo, $usuario));
    }

    /** Solo referencia y observaciones son editables (1.8), con auditoría. */
    public function editar(CreditoPago $pago, ?string $referencia, ?string $observaciones, User $usuario): CreditoPago
    {
        $this->alcance->asegurar($pago->credito, $usuario);
        $antes = $pago->only(['referencia', 'observaciones']);
        $pago->update(['referencia' => $referencia, 'observaciones' => $observaciones]);
        $this->auditoria->registrar('pago.editar', $pago, $pago->credito_id, $antes, $pago->only(['referencia', 'observaciones']), null, $usuario);

        return $pago->refresh();
    }

    private function ejecutar(CreditoPago $pago, string $motivo, User $usuario): CreditoPago
    {
        $credito = Credito::whereKey($pago->credito_id)->lockForUpdate()->firstOrFail();
        $pago = CreditoPago::whereKey($pago->id)->lockForUpdate()->firstOrFail();
        $this->alcance->asegurar($credito, $usuario);

        if ($pago->estado !== PagoEstado::Valido) {
            throw new HttpException(422, 'El pago ya está anulado.');
        }
        if ($pago->origen === OrigenPago::Renovacion) {
            throw new HttpException(422, 'Este pago cerró el crédito por una renovación. Para deshacerla, anula el crédito nuevo.');
        }
        $this->validarPermiso($pago, $usuario);
        // 1.8 (b): la mora de ese tramo se calcularía contra la fecha nueva. fecha_pago es hora de
        // Lima y created_at es UTC: se compara en UTC.
        $pagadoEnUtc = CarbonImmutable::parse($pago->fecha_pago->format('Y-m-d H:i:s'), Reloj::ZONA)->utc();
        if (CreditoReprogramacion::where('credito_id', $credito->id)->where('created_at', '>', $pagadoEnUtc)->exists()) {
            throw new HttpException(422, 'No se puede anular un pago anterior a una reprogramación de fechas. Usa una condonación o un ajuste.');
        }

        $pago->update([
            'estado' => PagoEstado::Anulado,
            'motivo_anulacion' => $motivo,
            'anulado_por' => $usuario->id,
            'anulado_en' => now(),
        ]);

        // Reaplica sin el pago; si una operación de cierre deja de alcanzar, el persistidor rechaza (1.8 a).
        $carga = $this->cargador->cargar($credito);
        $this->persistidor->persistir($credito, $carga, $this->aplicador->aplicar($carga->estado, $carga->pagos, $this->reloj->hoy()));

        $this->caja->revertirReferencia(CajaCredito::PAGO, $pago->id, $usuario);
        $this->caja->revertirReferencia(CajaCredito::DEVOLUCION_EXCEDENTE, $pago->id, $usuario);
        $this->saldos->revertirPago($pago->id, $usuario);

        $this->auditoria->registrar('pago.anular', $pago, $credito->id, ['estado' => 'valido'], ['estado' => 'anulado', 'monto' => $pago->monto_recibido], $motivo, $usuario);

        return $pago->refresh();
    }

    /** 00 1.14: el propio pago con su misma caja todavía abierta; cualquier otro caso, creditos.anular_pago. */
    private function validarPermiso(CreditoPago $pago, User $usuario): void
    {
        if ($usuario->can(self::PERMISO_ANULAR_CUALQUIERA)) {
            return;
        }
        $movimiento = $pago->cash_movement_id === null ? null : CashMovement::with('cashSession')->find($pago->cash_movement_id);
        $propioConCajaAbierta = $pago->registrado_por === $usuario->id
            && $movimiento !== null
            && $movimiento->cashSession->status === 'open'
            && $movimiento->cashSession->opened_by === $usuario->id;

        if (! $propioConCajaAbierta) {
            throw new HttpException(403, 'Solo puedes anular tus propios cobros mientras tu caja siga abierta. Para otros casos se requiere creditos.anular_pago.');
        }
    }
}
