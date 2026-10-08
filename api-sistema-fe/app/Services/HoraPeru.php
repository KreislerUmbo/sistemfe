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
}
