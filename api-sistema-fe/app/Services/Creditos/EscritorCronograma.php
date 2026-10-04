<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoCuota;
use App\Services\Creditos\Motor\Dto\Cronograma;
use App\Services\Creditos\Motor\Enums\EstadoCuota;

/** Guarda un cronograma del motor como una versión de credito_cuotas (00 1.9). */
class EscritorCronograma
{
    public function guardar(Credito $credito, Cronograma $cronograma, int $version): void
    {
        foreach ($cronograma->cuotas as $cuota) {
            CreditoCuota::create([
                'credito_id' => $credito->id,
                'version_cronograma' => $version,
                'numero_cuota' => $cuota->numero,
                'fecha_inicio_periodo' => $cuota->fechaInicioPeriodo->aTexto(),
                'fecha_vencimiento' => $cuota->fechaVencimiento->aTexto(),
                'fecha_vencimiento_original' => $cuota->fechaVencimiento->aTexto(),
                'monto_capital' => Dinero::aSoles($cuota->montoCapital),
                'monto_interes' => Dinero::aSoles($cuota->montoInteres),
                'monto_total' => Dinero::aSoles($cuota->montoTotal),
                'estado' => EstadoCuota::Pendiente,
            ]);
        }
    }

    /** Las cuotas de una versión reemplazada quedan anuladas, nunca se borran (1.9). */
    public function anularVersion(Credito $credito, int $version): void
    {
        CreditoCuota::where('credito_id', $credito->id)
            ->where('version_cronograma', $version)
            ->update(['estado' => EstadoCuota::Anulada]);
    }
}
