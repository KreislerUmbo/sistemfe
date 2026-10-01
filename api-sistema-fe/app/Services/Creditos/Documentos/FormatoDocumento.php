<?php

declare(strict_types=1);

namespace App\Services\Creditos\Documentos;

use App\Models\Creditos\Credito;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;
use App\Services\Creditos\Motor\Fecha;
use App\Services\TextoFormatoService;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Enums\UnidadTasa;
use Luecano\NumeroALetras\NumeroALetras;

/**
 * Textos de los documentos del módulo (Fase 4b): montos, fechas, tasa, frecuencia y regla de
 * mora tal como se imprimen. Los montos se formatean desde centavos o texto decimal, sin floats.
 */
final class FormatoDocumento
{
    /** 123456 (centavos) o "1234.56" → "S/ 1,234.56". */
    public static function soles(int|string $monto): string
    {
        $centavos = is_int($monto) ? $monto : Dinero::aCentavos($monto);
        $texto = Dinero::aSoles(abs($centavos));
        [$entero, $decimales] = explode('.', $texto);

        return ($centavos < 0 ? '-' : '') . 'S/ ' . number_format((int) $entero, 0, '.', ',') . '.' . $decimales;
    }

    /** Capital + interés del crédito, sumados en centavos. */
    public static function totalCredito(Credito $credito): string
    {
        return self::soles(Dinero::aCentavos((string) $credito->monto_capital) + Dinero::aCentavos((string) $credito->interes_total));
    }

    /** "MIL CON 00/100 SOLES" (mismo paquete que los comprobantes SUNAT). */
    public static function enLetras(int|string $monto): string
    {
        $centavos = is_int($monto) ? $monto : Dinero::aCentavos($monto);
        $entero = intdiv($centavos, 100);
        $letras = trim((new NumeroALetras())->toWords($entero));

        return sprintf('%s CON %02d/100 SOLES', $letras === '' ? 'CERO' : $letras, $centavos % 100);
    }

    public static function fecha(?\DateTimeInterface $fecha): string
    {
        return $fecha?->format('d/m/Y') ?? '—';
    }

    /** Fecha del motor o texto "Y-m-d" → "d/m/Y". */
    public static function fechaTexto(Fecha|string|null $fecha): string
    {
        if ($fecha === null || $fecha === '') {
            return '—';
        }
        [$anio, $mes, $dia] = explode('-', substr($fecha instanceof Fecha ? $fecha->aTexto() : $fecha, 0, 10));

        return "{$dia}/{$mes}/{$anio}";
    }

    /**
     * Texto libre para el PDF: DomPDF no tiene glifos de emoji (ej. "💵 Efectivo" sale como
     * "? Efectivo"). Mismo limpiador que los PDF de Agencia de Viajes.
     */
    public static function textoPdf(string $texto): string
    {
        return trim((string) TextoFormatoService::sanitizarHtmlParaPdf($texto));
    }

    /** Valor de un enum respaldado o del texto tal cual (columnas con y sin cast). */
    public static function valor(\BackedEnum|string|null $valor): string
    {
        return $valor instanceof \BackedEnum ? (string) $valor->value : (string) $valor;
    }

    /** "20% sobre el total" o "5% mensual" (sin ceros de relleno). */
    public static function tasa(Credito $credito): string
    {
        $tasa = rtrim(rtrim((string) $credito->tasa_interes, '0'), '.');

        return $credito->unidad_tasa === UnidadTasa::Total ? "{$tasa}% sobre el total" : "{$tasa}% mensual";
    }

    public static function frecuencia(Credito $credito): string
    {
        $n = $credito->frecuencia_intervalo;
        if ($n === 1) {
            return match ($credito->frecuencia_unidad) {
                FrecuenciaUnidad::Dia => 'diaria',
                FrecuenciaUnidad::Semana => 'semanal',
                FrecuenciaUnidad::Quincena => 'quincenal',
                FrecuenciaUnidad::Mes => 'mensual',
                FrecuenciaUnidad::Anio => 'anual',
            };
        }

        return 'cada ' . $n . ' ' . match ($credito->frecuencia_unidad) {
            FrecuenciaUnidad::Dia => 'días',
            FrecuenciaUnidad::Semana => 'semanas',
            FrecuenciaUnidad::Quincena => 'quincenas',
            FrecuenciaUnidad::Mes => 'meses',
            FrecuenciaUnidad::Anio => 'años',
        };
    }

    /** Regla de mora tal como la conoce el cliente (00 1.3, 1.19). */
    public static function reglaMora(Credito $credito): string
    {
        if (! $credito->cobra_mora) {
            return 'Este crédito no cobra interés moratorio por atraso en los pagos.';
        }
        $gracia = $credito->dias_gracia > 0 ? " después de {$credito->dias_gracia} día(s) de gracia" : '';
        $tope = match ($credito->tope_mora_tipo) {
            TopeMoraTipo::PorcentajeCuota => ", con un tope del {$credito->tope_mora_valor}% de la cuota",
            TopeMoraTipo::PorcentajeCapital => ", con un tope del {$credito->tope_mora_valor}% del capital",
            TopeMoraTipo::DiasMaximos => ", hasta un máximo de {$credito->tope_mora_valor} días",
            TopeMoraTipo::SinTope => '',
        };

        return "Por cada día de atraso{$gracia} se cobra un interés moratorio igual al saldo de la cuota dividido entre los días de su período{$tope}.";
    }
}
