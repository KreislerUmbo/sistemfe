<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CondonacionEstado;
use App\Enums\Creditos\ConceptoCondonacion;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoCondonacion;
use App\Models\Creditos\CreditoCuota;
use App\Models\User;
use App\Services\Creditos\Motor\AplicadorPagos;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Condonación de mora (00 1.6): vive en credito_condonaciones y sobrevive a los recálculos. */
class CondonacionService
{
    public function __construct(
        private readonly CargadorCredito $cargador,
        private readonly PersistidorReparto $persistidor,
        private readonly AplicadorPagos $aplicador,
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    public function condonar(Credito $credito, CreditoCuota $cuota, int $monto, string $motivo, User $usuario): CreditoCondonacion
    {
        return DB::transaction(function () use ($credito, $cuota, $monto, $motivo, $usuario): CreditoCondonacion {
            $credito = Credito::whereKey($credito->id)->lockForUpdate()->firstOrFail();
            if ($cuota->credito_id !== $credito->id || $cuota->version_cronograma !== $credito->version_cronograma_actual) {
                throw new HttpException(422, 'La cuota no pertenece al cronograma vigente del crédito.');
            }

            $hoy = $this->reloj->hoy();
            $carga = $this->cargador->cargar($credito);
            $pendiente = $this->aplicador->aplicar($carga->estado, $carga->pagos, $hoy)->mora($cuota->numero_cuota)->moraPendiente;
            if ($monto <= 0 || $monto > $pendiente) {
                throw new HttpException(422, 'Se puede condonar hasta S/ ' . Dinero::aSoles($pendiente) . ' de mora en esta cuota.');
            }

            $condonacion = CreditoCondonacion::create([
                'credito_id' => $credito->id,
                'cuota_id' => $cuota->id,
                'concepto' => ConceptoCondonacion::Mora,
                'monto' => Dinero::aSoles($monto),
                'motivo' => $motivo,
                'estado' => CondonacionEstado::Vigente,
                'registrado_por' => $usuario->id,
            ]);

            // Con la mora condonada el crédito puede quedar finalizado (1.5).
            $carga = $this->cargador->cargar($credito);
            $this->persistidor->persistir($credito, $carga, $this->aplicador->aplicar($carga->estado, $carga->pagos, $hoy));
            $this->auditoria->registrar('mora.condonar', $condonacion, $credito->id, ['mora_pendiente' => Dinero::aSoles($pendiente)], ['condonado' => Dinero::aSoles($monto)], $motivo, $usuario);

            return $condonacion;
        });
    }
}
