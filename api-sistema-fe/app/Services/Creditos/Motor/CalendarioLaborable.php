<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\FechaAjustada;
use App\Services\Creditos\Motor\Dto\ReglasCalendario;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Excepciones\CondicionesInvalidas;

/** Días laborables de un crédito (plan 1.4). */
final class CalendarioLaborable
{
    private const DIAS_SEMANA = 7;

    /** @var array<string, true> */
    private readonly array $feriados;

    public function __construct(private readonly ReglasCalendario $reglas)
    {
        foreach ($reglas->diasNoLaborables as $dia) {
            if (! is_int($dia) || $dia < 1 || $dia > self::DIAS_SEMANA) {
                throw new CondicionesInvalidas('Los días no laborables deben ser días ISO del 1 al 7.');
            }
        }
        if (count(array_unique($reglas->diasNoLaborables)) === self::DIAS_SEMANA) {
            throw new CondicionesInvalidas('Debe quedar al menos un día laborable en la semana.');
        }

        $feriados = [];
        foreach ($reglas->feriados as $feriado) {
            $feriados[$feriado->aTexto()] = true;
        }
        $this->feriados = $feriados;
    }

    public function esLaborable(Fecha $fecha): bool
    {
        if (in_array($fecha->diaIso(), $this->reglas->diasNoLaborables, true)) {
            return false;
        }

        return ! ($this->reglas->saltarFeriados && isset($this->feriados[$fecha->aTexto()]));
    }

    /** Primer día laborable desde $fecha inclusive. */
    public function siguienteLaborable(Fecha $fecha): Fecha
    {
        while (! $this->esLaborable($fecha)) {
            $fecha = $fecha->sumarDias(1);
        }

        return $fecha;
    }

    /** Aplica la regla del crédito. `anterior` nunca devuelve una fecha <= $limiteInferior. */
    public function ajustar(Fecha $fecha, Fecha $limiteInferior): Fecha
    {
        return $this->ajustarDetallado($fecha, $limiteInferior)->fecha;
    }

    /**
     * Igual que ajustar(), indicando si `anterior` era imposible y se usó `siguiente` (12.3):
     * retroceder no puede cruzar la cuota previa ni el desembolso.
     */
    public function ajustarDetallado(Fecha $fecha, Fecha $limiteInferior): FechaAjustada
    {
        if ($this->esLaborable($fecha)) {
            return new FechaAjustada($fecha);
        }

        return match ($this->reglas->regla) {
            ReglaNoLaborable::Mantener => new FechaAjustada($fecha),
            ReglaNoLaborable::Siguiente => new FechaAjustada($this->siguienteLaborable($fecha)),
            ReglaNoLaborable::Anterior => $this->retroceder($fecha, $limiteInferior),
        };
    }

    /** Días en el intervalo (desde, hasta]; con $contarNoLaborables=false cuenta solo los laborables. */
    public function diasEntre(Fecha $desde, Fecha $hasta, bool $contarNoLaborables): int
    {
        $dias = $desde->diasHasta($hasta);
        if ($dias <= 0) {
            return 0;
        }
        if ($contarNoLaborables) {
            return $dias;
        }

        $laborables = 0;
        for ($i = 1; $i <= $dias; $i++) {
            if ($this->esLaborable($desde->sumarDias($i))) {
                $laborables++;
            }
        }

        return $laborables;
    }

    private function retroceder(Fecha $fecha, Fecha $limiteInferior): FechaAjustada
    {
        $candidata = $fecha->sumarDias(-1);
        while (! $this->esLaborable($candidata)) {
            $candidata = $candidata->sumarDias(-1);
        }

        if ($candidata->esPosteriorA($limiteInferior)) {
            return new FechaAjustada($candidata);
        }

        return new FechaAjustada($this->siguienteLaborable($fecha), forzadaASiguiente: true);
    }
}
