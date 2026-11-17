<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// T-062 (CRM C6b) — Excel de «Reportes comerciales»: una hoja con las mismas secciones que la
// pantalla (embudo, por origen, por vendedor, motivos, tiempo de respuesta), una debajo de otra.
// Recibe los datos ya calculados por ReporteComercial; acá solo se arma la grilla.
// WithStrictNullComparison: sin él, los ceros salen como celdas vacías. Las filas en blanco
// llevan una celda '' (SEPARADOR): una fila [] se descarta y corre las negritas.
class ReporteComercialExport implements FromArray, ShouldAutoSize, WithProperties, WithStrictNullComparison, WithStyles, WithTitle
{
    private const SEPARADOR = [''];

    private const COLUMNAS_GRUPO = ['Consultas', 'Enviadas', 'Ganadas', 'Perdidas', 'Abiertas', 'Anuladas', '% conversión', 'Vendido S/', 'Vendido US$'];

    /** Filas (1-based) que van en negrita: títulos de sección y encabezados de tabla. */
    private array $negritas = [];

    public function __construct(
        private array $datos,
        private array $filtros,
        private string $textoFiltros,
        private string $generadoPor,
        private CarbonImmutable $generadoEn,
    ) {
    }

    public function title(): string
    {
        return 'Reportes comerciales';
    }

    public function array(): array
    {
        $f = [];
        $this->negrita($f, ['Reportes comerciales']);
        $f[] = ['Cotizaciones creadas del ' . $this->fecha($this->filtros['desde']) . ' al ' . $this->fecha($this->filtros['hasta'])];
        $f[] = [$this->textoFiltros];
        $f[] = ['Generado por ' . $this->generadoPor . ' el ' . $this->generadoEn->format('d/m/Y H:i')];
        $f[] = self::SEPARADOR;

        $e = $this->datos['embudo'];
        $this->negrita($f, ['Embudo']);
        $this->negrita($f, ['Etapa', 'Cantidad', '% de las consultas']);
        $f[] = ['Consultas', $e['consultas'], 100];
        foreach (['enviadas' => 'Enviadas', 'ganadas' => 'Ganadas', 'perdidas' => 'Perdidas', 'abiertas' => 'Abiertas', 'anuladas' => 'Anuladas (reserva cancelada)'] as $clave => $etiqueta) {
            $f[] = [$etiqueta, $e[$clave], $e['pct'][$clave]];
        }
        $f[] = ['Vendido S/', (float) $e['monto']['PEN']];
        $f[] = ['Vendido US$', (float) $e['monto']['USD']];
        $f[] = self::SEPARADOR;

        $this->grupo($f, 'Por origen', 'Origen', $this->datos['por_origen']);
        $this->grupo($f, 'Por vendedor', 'Vendedor', $this->datos['por_vendedor']);

        foreach (['ganada' => 'Motivos de ganancia', 'perdida' => 'Motivos de pérdida'] as $tipo => $titulo) {
            $this->negrita($f, [$titulo]);
            $this->negrita($f, ['Motivo', 'Cantidad', '%']);
            foreach ($this->datos['motivos'][$tipo] as $m) {
                $f[] = [$m['nombre'], $m['cantidad'], $m['pct']];
            }
            if (! $this->datos['motivos'][$tipo]) {
                $f[] = ['Sin datos'];
            }
            $f[] = self::SEPARADOR;
        }

        $t = $this->datos['tiempo_respuesta'];
        $this->negrita($f, ['Tiempo de respuesta']);
        $f[] = ['Mediana de horas entre la creación y el primer envío', $t['mediana_horas'] ?? '—'];
        $f[] = ['Cotizaciones con envío', $t['cotizaciones_con_envio']];

        return $f;
    }

    private function grupo(array &$f, string $titulo, string $columna, array $filas): void
    {
        $this->negrita($f, [$titulo]);
        $this->negrita($f, array_merge([$columna], self::COLUMNAS_GRUPO));
        foreach ($filas as $g) {
            $f[] = [
                $g['nombre'] . ($g['inactivo'] ? ' (inactivo)' : ''),
                $g['consultas'], $g['enviadas'], $g['ganadas'], $g['perdidas'], $g['abiertas'], $g['anuladas'],
                $g['conversion_pct'], (float) $g['monto']['PEN'], (float) $g['monto']['USD'],
            ];
        }
        if (! $filas) {
            $f[] = ['Sin datos'];
        }
        $f[] = self::SEPARADOR;
    }

    private function negrita(array &$f, array $fila): void
    {
        $f[] = $fila;
        $this->negritas[] = count($f);
    }

    private function fecha(string $ymd): string
    {
        return CarbonImmutable::parse($ymd)->format('d/m/Y');
    }

    public function styles(Worksheet $sheet): array
    {
        $estilos = [];
        foreach ($this->negritas as $fila) {
            $estilos[$fila] = ['font' => ['bold' => true]];
        }
        $estilos[1] = ['font' => ['bold' => true, 'size' => 14]];

        return $estilos;
    }

    public function properties(): array
    {
        return [
            'creator' => $this->generadoPor,
            'title' => 'Reportes comerciales del ' . $this->fecha($this->filtros['desde']) . ' al ' . $this->fecha($this->filtros['hasta']),
            'created' => $this->generadoEn->timestamp,
        ];
    }
}
