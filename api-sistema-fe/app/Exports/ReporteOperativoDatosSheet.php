<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Sesión rediseño-reporte-operativo — hoja "Datos": la tabla plana original de
// ReporteOperativoExport (una fila por reserva_item × pasajero, 27 columnas, sin
// merges), sin ningún cambio de comportamiento respecto a la versión anterior de
// esa clase. Existe para que el Excel siga siendo una herramienta de trabajo
// (Ordenar/Autofiltro funcionan) — la hoja "Vista operativa"
// (ReporteOperativoVistaOperativaSheet) usa celdas combinadas para ser legible al
// imprimir, y eso rompe Ordenar/Filtrar en Excel si fuera la única hoja.
class ReporteOperativoDatosSheet implements FromArray, WithStyles, WithTitle
{
    public function __construct(
        private Collection $filas,
        private string $fechaDesde,
        private string $fechaHasta,
        private string $generadoPor,
        private Carbon $generadoEn,
    ) {
    }

    public function title(): string
    {
        return 'Datos';
    }

    public function array(): array
    {
        $filasCabecera = [
            ['REPORTE OPERATIVO — DATOS'],
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
            'Vuelo ida (propio) - aerolínea',
            'Vuelo ida (propio) - fecha',
            'Vuelo ida (propio) - hora',
            'Vuelo vuelta (propio) - aerolínea',
            'Vuelo vuelta (propio) - fecha',
            'Vuelo vuelta (propio) - hora',
            // Vuelo vendido por la agencia (auditoría de UX/funcionalidad
            // 2026-08-27) — solo poblado en la fila del pasaje aéreo
            // cotizado, nunca se mezcla con las columnas "(propio)" de arriba.
            'Vuelo ida (agencia) - número',
            'Vuelo ida (agencia) - aerolínea',
            'Vuelo ida (agencia) - fecha',
            'Vuelo ida (agencia) - hora',
            'Vuelo vuelta (agencia) - número',
            'Vuelo vuelta (agencia) - aerolínea',
            'Vuelo vuelta (agencia) - fecha',
            'Vuelo vuelta (agencia) - hora',
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
            $fila['vuelo_agencia_ida']['numero'] ?? '',
            $fila['vuelo_agencia_ida']['aerolinea'] ?? '',
            $fila['vuelo_agencia_ida']['fecha'] ?? '',
            $fila['vuelo_agencia_ida']['hora'] ?? '',
            $fila['vuelo_agencia_vuelta']['numero'] ?? '',
            $fila['vuelo_agencia_vuelta']['aerolinea'] ?? '',
            $fila['vuelo_agencia_vuelta']['fecha'] ?? '',
            $fila['vuelo_agencia_vuelta']['hora'] ?? '',
            $fila['checkin_realizado'] ? 'Sí' : 'No',
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
