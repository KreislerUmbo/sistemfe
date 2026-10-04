<?php

declare(strict_types=1);

namespace App\Exports\Creditos;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reporte de créditos en Excel (Fase 4d): la misma tabla genérica que el PDF (TablasReporte), con
 * cabecera propia (título, período, generado por) y las secciones una debajo de otra.
 */
final class ReporteCreditoExport implements FromArray, WithStyles, WithTitle, ShouldAutoSize
{
    /** @var list<int> filas (1-based) en negrita: título, encabezados de sección y totales */
    private array $negritas = [];

    /** @param array{titulo: string, subtitulo: string, secciones: list<array<string, mixed>>} $tabla */
    public function __construct(private readonly array $tabla, private readonly string $generadoPor, private readonly string $generadoEn)
    {
    }

    public function title(): string
    {
        return mb_substr($this->tabla['titulo'], 0, 31);
    }

    public function array(): array
    {
        $filas = [[$this->tabla['titulo']], [$this->tabla['subtitulo']], ["Generado por {$this->generadoPor} el {$this->generadoEn}"], []];
        $this->negritas = [1];
        foreach ($this->tabla['secciones'] as $seccion) {
            if ($seccion['titulo']) {
                $filas[] = [$seccion['titulo']];
                $this->negritas[] = count($filas);
            }
            $filas[] = array_map(static fn (array $c): string => $c[0], $seccion['columnas']);
            $this->negritas[] = count($filas);
            foreach ($seccion['filas'] as $fila) {
                $filas[] = $fila;
            }
            if ($seccion['totales']) {
                $filas[] = $seccion['totales'];
                $this->negritas[] = count($filas);
            }
            $filas[] = [];
        }

        return $filas;
    }

    public function styles(Worksheet $hoja): array
    {
        if ($this->negritas === []) {
            $this->array();   // las filas en negrita se conocen al armar las filas
        }
        $estilos = [1 => ['font' => ['bold' => true, 'size' => 14]]];
        foreach (array_slice($this->negritas, 1) as $fila) {
            $estilos[$fila] = ['font' => ['bold' => true]];
        }

        return $estilos;
    }
}
