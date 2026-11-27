<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

// T-137 — una fila por grupo y moneda, y al pie un total por moneda. Recibe las cifras ya
// calculadas y redondeadas por CuentasPorPagarService; pasan a número solo al escribir la celda.
// WithStrictNullComparison: sin él, los ceros salen como celdas vacías.
class CuentasPorPagarExport extends DefaultValueBinder implements FromArray, WithColumnFormatting, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    // Documento, razón social y fichas son siempre texto: un nombre que empiece con «=» no es una
    // fórmula y un documento no es un número.
    private const COLUMNAS_DE_TEXTO = ['A', 'B', 'C'];

    private const COLUMNAS_DE_MONTO = ['E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O'];

    public function __construct(private array $grupos, private array $totales, private int $dias) {}

    public function headings(): array
    {
        return [
            'RUC / documento', 'Razón social', 'Fichas', 'Moneda',
            'Pendiente', 'Vencido', "Vence en {$this->dias} días",
            '1-30', '31-60', '61-90', '+90',
            'Por aprobar', 'A cuenta', 'A favor', 'Neto',
        ];
    }

    public function array(): array
    {
        $filas = [];
        foreach ($this->grupos as $grupo) {
            $fichas = implode(' / ', array_map(
                fn (array $f) => $f['nombre_comercial'] ?: $f['razon_social'],
                $grupo['fichas'],
            ));
            foreach ($grupo['monedas'] as $moneda) {
                $filas[] = $this->fila($grupo['documento'] ?? '', $grupo['razon_social'], $fichas, $moneda);
            }
        }
        foreach ($this->totales as $moneda) {
            $filas[] = $this->fila('', 'Total', '', $moneda);
        }

        return $filas;
    }

    public function columnFormats(): array
    {
        return array_fill_keys(self::COLUMNAS_DE_MONTO, '#,##0.00');
    }

    public function bindValue(Cell $cell, $value)
    {
        // La fila 1 son los encabezados: «+90» se leería como el número 90.
        if ($cell->getRow() === 1 || in_array($cell->getColumn(), self::COLUMNAS_DE_TEXTO, true)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    private function fila(string $documento, string $razonSocial, string $fichas, array $m): array
    {
        $montos = [
            $m['pendiente'], $m['vencido'], $m['por_vencer'],
            $m['antiguedad']['d1_30'], $m['antiguedad']['d31_60'], $m['antiguedad']['d61_90'], $m['antiguedad']['mas_90'],
            $m['por_aprobar'], $m['a_cuenta'], $m['a_favor'], $m['neto'],
        ];

        return [$documento, $razonSocial, $fichas, $m['moneda'], ...array_map('floatval', $montos)];
    }
}
