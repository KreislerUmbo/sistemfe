<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\TipoCorrelativo;
use App\Models\Creditos\Credito;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Activar un borrador (03-api): límites con bloqueo real dentro de la transacción (1.10),
 * cronograma guardado como versión 1, número CR- recién aquí y desembolso completo en caja
 * (1.12). Desde aquí las condiciones quedan congeladas (00 §4).
 */
class ActivacionService
{
    public function __construct(
        private readonly CreditoBorradorService $borradores,
        private readonly EscritorCronograma $escritor,
        private readonly LimitesService $limites,
        private readonly CorrelativoCreditoService $correlativos,
        private readonly CajaCredito $caja,
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    public function activar(Credito $credito, User $usuario): Credito
    {
        return DB::transaction(fn (): Credito => $this->ejecutar($credito, $usuario));
    }

    private function ejecutar(Credito $credito, User $usuario): Credito
    {
        $credito = Credito::whereKey($credito->id)->lockForUpdate()->firstOrFail();

        if ($credito->estado !== CreditoEstado::Borrador) {
            throw new HttpException(422, 'Solo se puede activar un crédito en borrador.');
        }
        if ($credito->payment_method_id === null) {
            throw new HttpException(422, 'Indica el método de entrega del dinero antes de activar.');
        }
        // El dinero sale hoy. Para fechas pasadas existe "Registrar crédito existente" (1.12).
        if ($credito->fecha_desembolso->format('Y-m-d') !== $this->reloj->hoy()->aTexto()) {
            throw new HttpException(422, 'La fecha de desembolso debe ser hoy. Actualiza el borrador antes de activarlo.');
        }

        $pendientes = $this->limites->bloqueosSinAutorizar(
            $credito,
            $this->limites->evaluar($credito->cliente_id, Dinero::aCentavos($credito->monto_capital)),
        );
        if ($pendientes !== []) {
            throw new LimitesExcedidos($pendientes);
        }

        $datos = $this->borradores->datosDe($credito);
        $this->escritor->guardar($credito, $this->borradores->preview($datos), 1);

        $movimiento = $this->caja->salida(
            $this->caja->sesionAbierta($usuario), $usuario, CajaCredito::DESEMBOLSO, $credito->id,
            $datos->montoCapital, $credito->payment_method_id, $credito->cliente, 'Desembolso de crédito',
        );

        $credito->update([
            'numero_credito' => $this->correlativos->siguiente(TipoCorrelativo::Credito),
            'estado' => CreditoEstado::Activo,
            'version_cronograma_actual' => 1,
            'cash_movement_id' => $movimiento->id,
            'asesor_id' => $this->borradores->asesorPara($credito->cliente_id, $usuario, $credito->asesor_id),
        ]);
        $this->auditoria->registrar('credito.activar', $credito, $credito->id, null, ['numero_credito' => $credito->numero_credito], null, $usuario);

        return $credito->refresh();
    }
}
