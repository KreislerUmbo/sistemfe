<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Sesión rediseño-reporte-operativo — hoja "Vista operativa": el layout
// jerárquico (Día → Tour/Servicios sueltos → Pasajero con celdas combinadas →
// servicio como sub-fila) que pidió el usuario a partir de su mockup, ya
// resuelto por ReporteOperativoController::armarVistaAgrupada(). A propósito
// NO es la hoja "de trabajo" (esa es ReporteOperativoDatosSheet, sin merges) —
// esta usa mergeCells, así que Ordenar/Autofiltro de Excel no se comportan bien
// acá (limitación conocida y aceptada: es la hoja para imprimir/repartir).
//
// Última columna, letra 'K' — si se agregan/quitan columnas de ENCABEZADOS()
// hay que actualizar ULTIMA_COLUMNA junto con el resto de este archivo.
class ReporteOperativoVistaOperativaSheet implements FromArray, WithStyles, WithTitle
{
    private const ULTIMA_COLUMNA = 'K';

    /** @var array<int, string> filas cuyo rango A:K debe fusionarse (encabezado de fecha) */
    private array $filasFecha = [];

    /** @var array<int, string> filas cuyo rango A:K debe fusionarse (encabezado de grupo) */
    private array $filasGrupo = [];

    /** @var array<int, true> filas de grupo cuyo guía quedó "Sin asignar" (resaltar) */
    private array $filasGrupoSinGuia = [];

    /** @var array<int, string> rangos "A{ini}:A{fin}" a fusionar por bloque de pasajero (una entrada por columna combinada) */
    private array $rangosPasajero = [];

    public function __construct(
        private array $dias,
        private string $fechaDesde,
        private string $fechaHasta,
        private string $generadoPor,
        private Carbon $generadoEn,
    ) {
    }

    public function title(): string
    {
        return 'Vista operativa';
    }

    public function array(): array
    {
        $filas = [
            ['REPORTE OPERATIVO'],
            ['Del ' . Carbon::parse($this->fechaDesde)->format('d/m/Y') . ' al ' . Carbon::parse($this->fechaHasta)->format('d/m/Y')],
            ['Generado por: ' . $this->generadoPor . ' · ' . $this->generadoEn->format('d/m/Y H:i')],
            [null],
            $this->encabezados(),
        ];

        // PhpSpreadsheet es 1-indexado; ya llevamos 5 filas arriba, la
        // siguiente fila que se agregue cae en la posición 6.
        $fila = 6;

        foreach ($this->dias as $dia) {
            $filas[] = [Carbon::parse($dia['fecha'])->translatedFormat('l d \d\e F')];
            $this->filasFecha[] = $fila;
            $fila++;

            foreach ($dia['grupos'] as $grupo) {
                $filas[] = [$this->tituloGrupo($grupo)];
                $this->filasGrupo[] = $fila;
                if ($grupo['es_tour'] && ! $grupo['guia']) {
                    $this->filasGrupoSinGuia[] = $fila;
                }
                $fila++;

                foreach ($grupo['pasajeros'] as $pax) {
                    $inicioBloque = $fila;
                    $primeraFila = true;

                    foreach ($pax['filas'] as $servicioFila) {
                        $filas[] = [
                            $primeraFila ? ($pax['pasajero']['documento'] ?? '') : null,
                            $primeraFila ? ($pax['pasajero']['tipo_pax'] ?? '') : null,
                            $primeraFila ? ($pax['pasajero']['nombre_display'] ?? '') : null,
                            $primeraFila ? ($pax['pasajero']['hotel_del_dia'] ?? '') : null,
                            $primeraFila ? ($pax['pasajero']['alimentacion_especial'] ?? '') : null,
                            $primeraFila ? ($pax['pasajero']['discapacidad'] ?? '') : null,
                            $servicioFila['hora'],
                            $servicioFila['servicio'],
                            $servicioFila['destino'],
                            $this->textoGuiaFila($grupo, $servicioFila),
                            $this->textoCheckin($servicioFila['checkin']),
                        ];
                        $primeraFila = false;
                        $fila++;
                    }

                    $finBloque = $fila - 1;
                    if ($finBloque > $inicioBloque) {
                        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $columna) {
                            $this->rangosPasajero[] = "{$columna}{$inicioBloque}:{$columna}{$finBloque}";
                        }
                    }
                }
            }
        }

        return $filas;
    }

    private function encabezados(): array
    {
        return [
            'Documento',
            'Tipo pax',
            'Pasajero',
            'Hotel',
            'Alimentación especial',
            'Discapacidad',
            'Hora',
            'Servicio',
            'Destino',
            'Guía',
            'Check-in',
        ];
    }

    private function tituloGrupo(array $grupo): string
    {
        if (! $grupo['es_tour']) {
            return $grupo['nombre'];
        }

        if (! $grupo['guia']) {
            return "{$grupo['nombre']} — Guía: Sin asignar";
        }

        $guia = $grupo['guia']['nombre'];
        if ($grupo['vehiculo']) {
            $guia .= " · {$grupo['vehiculo']}";
        }

        return "{$grupo['nombre']} — Guía: {$guia}";
    }

    // Guía por fila SOLO en grupos "Servicios sueltos" (sin escolta compartida):
    // en un Tour ya se muestra una vez en el header del grupo, repetirla acá
    // sería ruido. "Sin asignar" solo cuando ese ítem puntual SÍ necesita un
    // guía y no lo tiene (sin_guia) — nunca en filas donde el concepto de guía
    // no aplica (ej. Vuelo, Alojamiento).
    private function textoGuiaFila(array $grupo, array $servicioFila): string
    {
        if ($grupo['es_tour']) {
            return '';
        }

        if ($servicioFila['guia']) {
            return $servicioFila['guia']['nombre'];
        }

        return $servicioFila['sin_guia'] ? 'Sin asignar' : '';
    }

    private function textoCheckin(?bool $checkin): string
    {
        if ($checkin === null) {
            return '—';
        }

        return $checkin ? 'Sí' : 'No';
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A5:' . self::ULTIMA_COLUMNA . '5')->getFont()->setBold(true);

        foreach ($this->filasFecha as $filaIdx) {
            $rango = "A{$filaIdx}:" . self::ULTIMA_COLUMNA . $filaIdx;
            $sheet->mergeCells($rango);
            $sheet->getStyle($rango)->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6E6E6');
        }

        foreach ($this->filasGrupo as $filaIdx) {
            $rango = "A{$filaIdx}:" . self::ULTIMA_COLUMNA . $filaIdx;
            $sheet->mergeCells($rango);
            $sheet->getStyle($rango)->getFont()->setBold(true);
            $sheet->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');

            if (in_array($filaIdx, $this->filasGrupoSinGuia, true)) {
                $sheet->getStyle($rango)->getFont()->getColor()->setRGB('B45309');
            }
        }

        foreach ($this->rangosPasajero as $rango) {
            $sheet->mergeCells($rango);
        }

        $sheet->getStyle('A1:' . self::ULTIMA_COLUMNA . ($sheet->getHighestRow()))
            ->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        foreach (range('A', self::ULTIMA_COLUMNA) as $columna) {
            $sheet->getColumnDimension($columna)->setAutoSize(true);
        }

        return [];
    }
}
