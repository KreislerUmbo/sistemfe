<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor;

/**
 * Fecha calendario sin hora ni zona horaria. El motor no usa Carbon: evita depender de la zona
 * del proceso y del cambio de signo de diffInDays() en Carbon 3.
 * Internamente se trabaja con un número de día serial (días desde 1970-01-01).
 */
final readonly class Fecha
{
    private function __construct(
        public int $anio,
        public int $mes,
        public int $dia,
    ) {
    }

    public static function crear(int $anio, int $mes, int $dia): self
    {
        if (! checkdate($mes, $dia, $anio)) {
            throw new \InvalidArgumentException("Fecha inválida: {$anio}-{$mes}-{$dia}.");
        }

        return new self($anio, $mes, $dia);
    }

    /** @param string $ymd formato 'Y-m-d' */
    public static function desdeTexto(string $ymd): self
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            throw new \InvalidArgumentException("Formato de fecha inválido: {$ymd}.");
        }

        return self::crear((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    public function sumarDias(int $dias): self
    {
        return self::desdeSerial($this->serial() + $dias);
    }

    /**
     * Suma meses conservando el día ancla y ajustando al último día del mes cuando no existe
     * (plan 1.3: 31/01 → 28/02 → 31/03).
     */
    public function sumarMesesAnclado(int $meses, int $diaAncla): self
    {
        $indice = $this->anio * 12 + ($this->mes - 1) + $meses;
        $anio = intdiv($indice, 12);
        $mes = $indice % 12 + 1;

        return new self($anio, $mes, min($diaAncla, self::diasEnMes($anio, $mes)));
    }

    /** Mismo año y mes, con el día ajustado al último del mes si no existe. */
    public function conDia(int $dia): self
    {
        return new self($this->anio, $this->mes, min($dia, self::diasEnMes($this->anio, $this->mes)));
    }

    public function ultimoDiaDelMes(): self
    {
        return new self($this->anio, $this->mes, self::diasEnMes($this->anio, $this->mes));
    }

    /** 1 = lunes … 7 = domingo. */
    public function diaIso(): int
    {
        // 1970-01-01 fue jueves (4).
        return (($this->serial() + 3) % 7 + 7) % 7 + 1;
    }

    /** Días desde esta fecha hasta $otra (positivo si $otra es posterior). */
    public function diasHasta(self $otra): int
    {
        return $otra->serial() - $this->serial();
    }

    public function comparar(self $otra): int
    {
        return $this->serial() <=> $otra->serial();
    }

    public function esAnteriorA(self $otra): bool
    {
        return $this->comparar($otra) < 0;
    }

    public function esPosteriorA(self $otra): bool
    {
        return $this->comparar($otra) > 0;
    }

    public function esIgualA(self $otra): bool
    {
        return $this->comparar($otra) === 0;
    }

    public static function menor(self $a, self $b): self
    {
        return $a->esAnteriorA($b) ? $a : $b;
    }

    public function aTexto(): string
    {
        return sprintf('%04d-%02d-%02d', $this->anio, $this->mes, $this->dia);
    }

    public function __toString(): string
    {
        return $this->aTexto();
    }

    private static function diasEnMes(int $anio, int $mes): int
    {
        if ($mes === 2) {
            $bisiesto = ($anio % 4 === 0 && $anio % 100 !== 0) || $anio % 400 === 0;

            return $bisiesto ? 29 : 28;
        }

        return in_array($mes, [4, 6, 9, 11], true) ? 30 : 31;
    }

    /** Algoritmo days_from_civil (H. Hinnant), exacto para el calendario gregoriano proléptico. */
    private function serial(): int
    {
        $y = $this->mes <= 2 ? $this->anio - 1 : $this->anio;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($this->mes + ($this->mes > 2 ? -3 : 9)) + 2, 5) + $this->dia - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    private static function desdeSerial(int $z): self
    {
        $z += 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $dia = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $mes = $mp < 10 ? $mp + 3 : $mp - 9;
        $anio = $yoe + $era * 400 + ($mes <= 2 ? 1 : 0);

        return new self($anio, $mes, $dia);
    }
}
