<?php

declare(strict_types=1);

namespace App\Services\Creditos\Reportes;

use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Reloj;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rango de fechas de un reporte (04d), en días de Lima e inclusivo en ambos extremos. Los
 * created_at se guardan en UTC: desdeUtc()/hastaUtc() dan los límites para compararlos.
 * credito_pagos.fecha_pago ya está en hora de Lima: se compara con desde()/hasta() como texto.
 */
final readonly class Periodo
{
    public const MAXIMO_DIAS = 366;

    private function __construct(public Fecha $desde, public Fecha $hasta)
    {
    }

    public static function entre(Fecha $desde, Fecha $hasta, int $maximoDias = self::MAXIMO_DIAS): self
    {
        if ($hasta->esAnteriorA($desde)) {
            throw new HttpException(422, 'La fecha final no puede ser anterior a la inicial.');
        }
        if ($desde->diasHasta($hasta) >= $maximoDias) {
            throw new HttpException(422, "El rango no puede superar {$maximoDias} días.");
        }

        return new self($desde, $hasta);
    }

    public static function dia(Fecha $dia): self
    {
        return new self($dia, $dia);
    }

    /** Inicio del primer día en UTC (para created_at). */
    public function desdeUtc(): string
    {
        return CarbonImmutable::parse($this->desde->aTexto() . ' 00:00:00', Reloj::ZONA)->utc()->format('Y-m-d H:i:s');
    }

    /** Inicio del día siguiente al último, en UTC (límite exclusivo para created_at). */
    public function hastaUtc(): string
    {
        return CarbonImmutable::parse($this->hasta->sumarDias(1)->aTexto() . ' 00:00:00', Reloj::ZONA)->utc()->format('Y-m-d H:i:s');
    }

    /** Límite exclusivo para fecha_pago (texto en hora de Lima). */
    public function hastaExclusivo(): string
    {
        return $this->hasta->sumarDias(1)->aTexto();
    }

    /** @return list<Fecha> todos los días del rango */
    public function dias(): array
    {
        $dias = [];
        for ($d = $this->desde; ! $d->esPosteriorA($this->hasta); $d = $d->sumarDias(1)) {
            $dias[] = $d;
        }

        return $dias;
    }

    public function texto(): string
    {
        $formato = static fn (Fecha $f): string => substr($f->aTexto(), 8, 2) . '/' . substr($f->aTexto(), 5, 2) . '/' . substr($f->aTexto(), 0, 4);

        return $this->desde->esIgualA($this->hasta) ? $formato($this->desde) : $formato($this->desde) . ' al ' . $formato($this->hasta);
    }
}
