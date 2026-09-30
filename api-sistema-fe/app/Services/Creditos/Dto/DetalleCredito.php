<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Models\Creditos\Credito;
use App\Services\Creditos\Motor\Dto\ResultadoAplicacion;

/** Crédito con su situación calculada en vivo a hoy (mora, exigible, cabecera). Montos en centavos. */
final readonly class DetalleCredito
{
    public function __construct(
        public Credito $credito,
        public ?ResultadoAplicacion $situacion,
        public int $exigible,
        public int $saldoCapital,
        public int $saldoInteres,
        public int $diasAtraso,
        /** Capital + interés + cargos + mora pendientes: lo que falta para cancelar según cronograma. */
        public int $saldoPorPagar = 0,
        public int $totalPagado = 0,
        public int $cuotasPagadas = 0,
        public int $cuotasVencidas = 0,
        public ?ProximaCuota $proxima = null,
    ) {
    }
}
