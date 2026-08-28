<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithProperties;

// Sesión rediseño-reporte-operativo — orquestador de 2 hojas: "Vista operativa"
// (jerárquica, celdas combinadas — para imprimir/repartir, ver
// ReporteOperativoVistaOperativaSheet) y "Datos" (la tabla plana original de
// esta clase, sin merges, con Autofiltro — para quien necesite trabajar los
// datos, ver ReporteOperativoDatosSheet). Celdas combinadas rompen Ordenar/
// Filtrar en Excel (rango de tamaño desigual → error al ordenar; Autofiltro
// deja bloques "huérfanos" al ocultar la fila donde vive el valor combinado),
// por eso ninguna hoja intenta ser las dos cosas a la vez.
class ReporteOperativoExport implements WithMultipleSheets, WithProperties
{
    public function __construct(
        private Collection $filas,
        private array $dias,
        private string $fechaDesde,
        private string $fechaHasta,
        private string $generadoPor,
        private Carbon $generadoEn,
    ) {
    }

    public function sheets(): array
    {
        return [
            new ReporteOperativoVistaOperativaSheet(
                $this->dias,
                $this->fechaDesde,
                $this->fechaHasta,
                $this->generadoPor,
                $this->generadoEn
            ),
            new ReporteOperativoDatosSheet(
                $this->filas,
                $this->fechaDesde,
                $this->fechaHasta,
                $this->generadoPor,
                $this->generadoEn
            ),
        ];
    }

    public function properties(): array
    {
        return [
            'creator' => $this->generadoPor,
            'title' => 'Reporte Operativo del ' . Carbon::parse($this->fechaDesde)->format('d/m/Y')
                . ' al ' . Carbon::parse($this->fechaHasta)->format('d/m/Y'),
            'created' => $this->generadoEn->timestamp,
        ];
    }
}
