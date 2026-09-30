<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\OrigenRegistro;
use App\Enums\Creditos\PagoEstado;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\Dto\DatosCredito;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Corregir y anular un crédito (00 1.9). Solo sin pagos válidos: con pagos, primero se anulan.
 * Anular un crédito de renovación deshace la renovación: revierte la salida neta y reabre el
 * crédito anterior (03-api decisión 2).
 */
class CorreccionService
{
    private const CONDICIONES_AUDITADAS = [
        'monto_capital', 'tasa_interes', 'unidad_tasa', 'interes_total', 'frecuencia_unidad', 'frecuencia_intervalo',
        'numero_cuotas', 'fecha_desembolso', 'fecha_primer_vencimiento', 'tasa_interes_minimo', 'dias_gracia',
    ];

    public function __construct(
        private readonly CreditoBorradorService $borradores,
        private readonly EscritorCronograma $escritor,
        private readonly CargadorCredito $cargador,
        private readonly PersistidorReparto $persistidor,
        private readonly AplicadorPagos $aplicador,
        private readonly CajaCredito $caja,
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    public function corregir(Credito $credito, DatosCredito $datos, string $motivo, User $usuario): Credito
    {
        return DB::transaction(function () use ($credito, $datos, $motivo, $usuario): Credito {
            $credito = $this->bloquearSinPagos($credito, [CreditoEstado::Activo]);
            if ($credito->origen_registro !== OrigenRegistro::Normal) {
                throw new HttpException(422, 'Un crédito migrado o de renovación no se corrige: anúlalo y regístralo otra vez.');
            }
            $antes = $credito->only(self::CONDICIONES_AUDITADAS);
            $datos = $datos->conCliente($credito->cliente_id);
            $cronograma = $this->borradores->preview($datos);

            $version = $credito->version_cronograma_actual + 1;
            $this->escritor->anularVersion($credito, $credito->version_cronograma_actual);
            $this->escritor->guardar($credito, $cronograma, $version);
            $capitalAnterior = Dinero::aCentavos($credito->monto_capital);
            $credito->update([...$this->borradores->atributos($datos, $cronograma), 'version_cronograma_actual' => $version]);

            // CashCorrectionService solo revierte completo: reverso + nuevo desembolso (03-api decisión 3).
            if ($datos->montoCapital !== $capitalAnterior) {
                $this->caja->revertirReferencia(CajaCredito::DESEMBOLSO, $credito->id, $usuario);
                $movimiento = $this->caja->salida(
                    $this->caja->sesionAbierta($usuario), $usuario, CajaCredito::DESEMBOLSO, $credito->id,
                    $datos->montoCapital, $datos->paymentMethodId ?? $credito->payment_method_id, $credito->cliente, 'Desembolso corregido',
                );
                $credito->update(['cash_movement_id' => $movimiento->id]);
            }

            $this->auditoria->registrar('credito.corregir', $credito, $credito->id, $antes, $credito->only(self::CONDICIONES_AUDITADAS), $motivo, $usuario);

            return $credito->refresh();
        });
    }

    public function anular(Credito $credito, string $motivo, User $usuario): Credito
    {
        return DB::transaction(function () use ($credito, $motivo, $usuario): Credito {
            $credito = $this->bloquearSinPagos($credito, [CreditoEstado::Borrador, CreditoEstado::Activo]);
            $estabaActivo = $credito->estado === CreditoEstado::Activo;

            if ($estabaActivo) {
                $this->caja->revertirReferencia(CajaCredito::DESEMBOLSO, $credito->id, $usuario);
                $this->escritor->anularVersion($credito, $credito->version_cronograma_actual);
            }
            $credito->update([
                'estado' => CreditoEstado::Anulado,
                'motivo_anulacion' => $motivo,
                'anulado_por' => $usuario->id,
                'anulado_en' => now(),
            ]);
            if ($estabaActivo && $credito->origen_registro === OrigenRegistro::Renovacion) {
                $this->reabrirRenovado($credito, $motivo, $usuario);
            }

            $this->auditoria->registrar('credito.anular', $credito, $credito->id, ['estado' => $estabaActivo ? 'activo' : 'borrador'], ['estado' => 'anulado'], $motivo, $usuario);

            return $credito->refresh();
        });
    }

    /** El pago "renovación" del crédito anterior (sin caja) se anula y el anterior vuelve a quedar abierto. */
    private function reabrirRenovado(Credito $nuevo, string $motivo, User $usuario): void
    {
        $anterior = Credito::whereKey($nuevo->credito_renovado_id)->lockForUpdate()->firstOrFail();
        $pago = CreditoPago::where('credito_id', $anterior->id)
            ->where('origen', OrigenPago::Renovacion)
            ->where('estado', PagoEstado::Valido)
            ->firstOrFail();
        $pago->update(['estado' => PagoEstado::Anulado, 'motivo_anulacion' => "Renovación anulada: {$motivo}", 'anulado_por' => $usuario->id, 'anulado_en' => now()]);

        $carga = $this->cargador->cargar($anterior);
        $this->persistidor->persistir($anterior, $carga, $this->aplicador->aplicar($carga->estado, $carga->pagos, $this->reloj->hoy()));
    }

    /** @param list<CreditoEstado> $estados */
    private function bloquearSinPagos(Credito $credito, array $estados): Credito
    {
        $credito = Credito::whereKey($credito->id)->lockForUpdate()->firstOrFail();
        if (! in_array($credito->estado, $estados, true)) {
            throw new HttpException(422, 'Esta acción no está disponible para el estado actual del crédito.');
        }
        if ($credito->pagos()->where('estado', PagoEstado::Valido)->exists()) {
            throw new HttpException(422, 'El crédito tiene pagos válidos. Anúlalos primero.');
        }

        return $credito;
    }
}
