<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * "Ahora" en hora de Perú para lo que ve el usuario (PDF, nombres de archivo) y para
 * fechas de negocio ("hoy", "mes actual"). La app corre en UTC (config/app.php): un
 * now() a secas sale 5 horas adelantado y, de 19:00 a 24:00, ya en el día siguiente.
 * Mismo criterio que App\Services\Creditos\Reloj, que el módulo Créditos sigue usando.
 */
class HoraPeru
{
    public const ZONA = 'America/Lima';

    public static function ahora(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA);
    }

    /**
     * Fecha guardada en UTC (created_at de las tablas que no fuerzan hora Lima, opened_at/closed_at
     * de caja…) pasada a hora de Perú para mostrarla. Toma la hora tal como está en la BD y la
     * interpreta como UTC: no depende de la zona por defecto de PHP, que algunos modelos cambian a
     * mitad de la petición (date_default_timezone_set en sus mutadores).
     * No usar con columnas que ya se guardan en hora Lima: las correría 5 horas.
     */
    public static function deUtc(\DateTimeInterface|string|null $valor): ?CarbonImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $texto = $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d H:i:s') : $valor;

        return CarbonImmutable::parse($texto, 'UTC')->setTimezone(self::ZONA);
    }
}
