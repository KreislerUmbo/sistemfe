<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Sesión 11d — pedido nuevo del usuario, no estaba en plan-modulo-cotizaciones-
// reservas.md §8 (que solo pedía PDF). FromArray en vez de FromCollection+WithHeadings
// (esquema original de esta clase): una hoja de cálculo no tiene "encabezado de página"
// como un PDF, así que la convención "quién generó y cuándo" se resuelve con 3 filas
// visibles antes de la tabla — mismo criterio que reportes reales de agencia, no solo
// WithProperties (que Excel muestra en "Propiedades del archivo", casi nadie lo mira).
class ReporteOperativoExport implements FromArray, WithProperties, WithStyles
{
    public function __construct(
        private Collection $filas,
        private string $fechaDesde,
        private string $fechaHasta,
        private string $generadoPor,
        private Carbon $generadoEn,
    ) {
    }

    public function array(): array
    {
        $filasCabecera = [
            ['REPORTE OPERATIVO'],
            ['Del ' . Carbon::parse($this->fechaDesde)->format('d/m/Y') . ' al ' . Carbon::parse($this->fechaHasta)->format('d/m/Y')],
            ['Generado por: ' . $this->generadoPor . ' · ' . $this->generadoEn->format('d/m/Y H:i')],
            // [null], no []: una fila realmente vacía (sin celdas) se colapsa en
            // silencio al exportar (confirmado inspeccionando el .xlsx generado — sin
            // esto, "encabezados()" terminaba en la fila 4, no la 5, y styles()
            // le ponía negrita a la fila equivocada).
            [null],
            $this->encabezados(),
        ];

        $filasDatos = $this->filas->map(fn ($fila) => $this->mapaFila($fila))->all();

        return array_merge($filasCabecera, $filasDatos);
    }

    private function encabezados(): array
    {
        return [
            'Fecha',
            'Hora',
            'Pasajero',
            'Documento',
            'Tipo pax',
            'Servicio',
            'Destino',
            'Hotel',
            'Guía',
            'Sin guía',
            'Tipo de asignación',
            'Alimentación especial',
            'Discapacidad',
            'Vuelo ida - aerolínea',
            'Vuelo ida - fecha',
            'Vuelo ida - hora',
            'Vuelo vuelta - aerolínea',
            'Vuelo vuelta - fecha',
            'Vuelo vuelta - hora',
            'Check-in',
        ];
    }

    private function mapaFila(array $fila): array
    {
        return [
            $fila['fecha'],
            $fila['hora'],
            $fila['pasajero']['nombre'],
            $fila['pasajero']['documento'],
            $fila['pasajero']['tipo_pax'],
            $fila['servicio'],
            $fila['destino'],
            $fila['hotel'],
            $fila['guia']['nombre'] ?? '',
            $fila['sin_guia'] ? 'Sí' : 'No',
            $fila['origen_tipo'] ?? '',
            $fila['pasajero']['alimentacion_especial'],
            $fila['pasajero']['discapacidad'],
            $fila['vuelo_ida']['aerolinea'] ?? '',
            $fila['vuelo_ida']['fecha'] ?? '',
            $fila['vuelo_ida']['hora'] ?? '',
            $fila['vuelo_vuelta']['aerolinea'] ?? '',
            $fila['vuelo_vuelta']['fecha'] ?? '',
            $fila['vuelo_vuelta']['hora'] ?? '',
            $fila['checkin_realizado'] ? 'Sí' : 'No',
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

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            5 => ['font' => ['bold' => true]],
        ];
    }
}
