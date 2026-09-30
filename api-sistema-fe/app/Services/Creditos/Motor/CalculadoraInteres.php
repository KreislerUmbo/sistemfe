<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\Frecuencia;
use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;
use App\Services\Creditos\Motor\Enums\TipoCargoReprogramacion;
use App\Services\Creditos\Motor\Enums\UnidadTasa;

/** Cálculos de interés `simple_fijo` (plan 1.1, 12.1). Montos en centavos. */
final class CalculadoraInteres
{
    /** Mes comercial para convertir tasas mensuales a frecuencias no mensuales (12.1). */
    private const DIAS_MES_COMERCIAL = 30;
    private const DIAS_SEMANA = 7;
    private const MESES_ANIO = 12;
    private const QUINCENAS_MES = 2;
    private const CIEN_POR_CIENTO = 100;

    /**
     * Interés total pactado, calculado una sola vez sobre el capital inicial y redondeado una
     * sola vez al paso (12.1): total → capital × tasa; mensual → capital × tasa × meses del plazo.
     */
    public function interesTotal(
        int $capital,
        Tasa $tasa,
        UnidadTasa $unidad,
        Frecuencia $frecuencia,
        int $numeroCuotas,
        int $paso = Redondeo::PASO_DIEZ_CENTIMOS,
    ): int {
        if ($unidad === UnidadTasa::Total) {
            return $this->montoPorTasa($capital, $tasa, $paso);
        }

        [$mesesNum, $mesesDen] = $this->mesesPorCuota($frecuencia);

        return Redondeo::dividir(
            Redondeo::multiplicar($capital, $tasa->diezmilesimas, $numeroCuotas, $mesesNum),
            Redondeo::multiplicar(Tasa::DENOMINADOR, $mesesDen),
            $paso,
        );
    }

    /** base × tasa (interés mínimo de liquidación, topes porcentuales). */
    public function montoPorTasa(int $base, Tasa $tasa, int $paso): int
    {
        return Redondeo::dividir(Redondeo::multiplicar($base, $tasa->diezmilesimas), Tasa::DENOMINADOR, $paso);
    }

    /** base × porcentaje entero / 100. */
    public function montoPorPorcentaje(int $base, int $porcentaje, int $paso): int
    {
        return Redondeo::dividir(Redondeo::multiplicar($base, $porcentaje), self::CIEN_POR_CIENTO, $paso);
    }

    /** monto × días transcurridos / días del período. */
    public function proporcional(int $monto, int $diasTranscurridos, int $diasPeriodo, int $paso): int
    {
        if ($diasTranscurridos <= 0) {
            return 0;
        }
        if ($diasPeriodo <= 0 || $diasTranscurridos >= $diasPeriodo) {
            return $monto;
        }

        return Redondeo::dividir(Redondeo::multiplicar($monto, $diasTranscurridos), $diasPeriodo, $paso);
    }

    /** 1.18 interes_por_dias: interés total ÷ días del plazo original × días que se extiende la última cuota. */
    public function cargoPorDias(int $interesTotal, int $diasPlazoOriginal, int $diasExtension, int $paso): int
    {
        if ($diasExtension <= 0 || $diasPlazoOriginal <= 0) {
            return 0;
        }

        return Redondeo::dividir(Redondeo::multiplicar($interesTotal, $diasExtension), $diasPlazoOriginal, $paso);
    }

    /** Cargo sugerido por reprogramación según la configuración (1.18). */
    public function cargoReprogramacion(
        TipoCargoReprogramacion $tipo,
        int $montoFijo,
        int $interesTotal,
        int $diasPlazoOriginal,
        int $diasExtension,
        int $paso,
    ): int {
        return match ($tipo) {
            TipoCargoReprogramacion::Ninguno => 0,
            TipoCargoReprogramacion::Fijo => $montoFijo,
            TipoCargoReprogramacion::InteresPorDias => $this->cargoPorDias($interesTotal, $diasPlazoOriginal, $diasExtension, $paso),
        };
    }

    /** @return array{int, int} meses por cuota como fracción [numerador, denominador] (12.1) */
    private function mesesPorCuota(Frecuencia $f): array
    {
        return match ($f->unidad) {
            FrecuenciaUnidad::Mes => [$f->intervalo, 1],
            FrecuenciaUnidad::Anio => [self::MESES_ANIO * $f->intervalo, 1],
            FrecuenciaUnidad::Quincena => [$f->intervalo, self::QUINCENAS_MES],
            FrecuenciaUnidad::Semana => [self::DIAS_SEMANA * $f->intervalo, self::DIAS_MES_COMERCIAL],
            FrecuenciaUnidad::Dia => [$f->intervalo, self::DIAS_MES_COMERCIAL],
        };
    }
}
