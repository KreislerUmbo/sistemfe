<?php

declare(strict_types=1);

namespace App\Services\Creditos\Reportes;

use App\Models\Creditos\Credito;
use App\Services\Creditos\Dto\ProximaCuota;

/**
 * Situación de un crédito a una fecha para los reportes (04d), calculada con el motor (mismas
 * piezas que el detalle del crédito). Montos en centavos.
 */
final readonly class SituacionCartera
{
    public function __construct(
        public Credito $credito,
        public int $saldoCapital,
        public int $saldoInteres,
        public int $cargos,
        public int $mora,
        public int $diasAtraso,
        public int $cuotasPagadas,
        public int $cuotasTotal,
        public ?ProximaCuota $proxima,
    ) {
    }

    /** Cartera en riesgo (criterio SBS, 04d): atraso mayor a los días de gracia del crédito. */
    public function enRiesgo(): bool
    {
        return $this->diasAtraso > $this->credito->dias_gracia;
    }

    /** Rango de morosidad del reporte y de la foto diaria. */
    public function rangoAtraso(): string
    {
        return self::rango($this->diasAtraso);
    }

    public static function rango(int $dias): string
    {
        return match (true) {
            $dias <= 0 => 'al_dia',
            $dias <= 7 => '1-7',
            $dias <= 15 => '8-15',
            $dias <= 30 => '16-30',
            $dias <= 60 => '31-60',
            default => '60+',
        };
    }
}
