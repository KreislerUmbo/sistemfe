<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

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
     * "Hoy" de Perú como fecha de negocio (Y-m-d): fecha de emisión, de pago, vigencias, filtros.
     */
    public static function hoyTexto(): string
    {
        return self::ahora()->toDateString();
    }

    /**
     * "Hoy" de Perú a medianoche, en la zona por defecto de PHP: para comparar o restar contra
     * columnas `date` (vencimientos, fecha_limite_pago), que Eloquent también lee en esa zona.
     * No usar ahora()->startOfDay() para eso: la medianoche de Lima son las 05:00 UTC y quedaría
     * "después" de un vencimiento del mismo día.
     */
    public static function hoy(): Carbon
    {
        return Carbon::parse(self::hoyTexto());
    }

    /**
     * Inicio del día $ymd de Perú expresado en UTC: límite inclusivo para filtrar columnas que se
     * guardan en UTC (where col >= desdeUtc). Un día de Perú va de 05:00 UTC a 05:00 UTC del día
     * siguiente; whereDate(col, $ymd) sobre una columna UTC corta el día a las 19:00 de Perú.
     */
    public static function inicioDiaUtc(string $ymd): string
    {
        return CarbonImmutable::parse(substr($ymd, 0, 10) . ' 00:00:00', self::ZONA)->utc()->format('Y-m-d H:i:s');
    }

    /** Inicio del día SIGUIENTE a $ymd de Perú, en UTC: límite exclusivo (where col < finDiaUtc). */
    public static function finDiaUtc(string $ymd): string
    {
        return CarbonImmutable::parse(substr($ymd, 0, 10) . ' 00:00:00', self::ZONA)->addDay()->utc()->format('Y-m-d H:i:s');
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

    /**
     * Fecha-hora escrita por el usuario en hora de Perú ("2026-10-10 18:00") pasada a UTC para
     * guardarla como instante. Inverso de deUtc().
     */
    public static function aUtc(?string $textoPeru): ?string
    {
        if ($textoPeru === null || trim($textoPeru) === '') {
            return null;
        }

        return CarbonImmutable::parse($textoPeru, self::ZONA)->utc()->format('Y-m-d H:i:s');
    }
}
