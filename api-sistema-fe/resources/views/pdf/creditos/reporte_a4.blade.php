{{-- Módulo Créditos (Fase 4d): reportes en A4 horizontal. Una plantilla para los 7 reportes:
     título, subtítulo y secciones (TablasReporte). La agenda pone un cobrador por página. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    @include('pdf.creditos._estilos_a4')
    <style>
        @page { margin: 12mm 10mm 16mm; }
        .tabla td, .tabla th { font-size: 9px; padding: 4px; }
        .tabla .fila-total td { font-weight: bold; background: #f2f2f2; border-top: 1px solid #111111; }
        .subtitulo { font-size: 11px; margin: -6px 0 6px; }
        .generado { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 9px; color: #666666; text-align: right; }
        .salto { page-break-before: always; }
    </style>
</head>
<body>
    <div class="generado">Generado por {{ $generadoPor }} el {{ $generadoEn }}</div>
    {{-- Sin el pie de la cabecera: el reporte ya lleva "Generado por … el …" (salían dos superpuestos). --}}
    @include('pdf.creditos._cabecera_a4', ['titulo' => $tabla['titulo'], 'numero' => null, 'sinPie' => true])
    <div class="subtitulo">{{ $tabla['subtitulo'] }}</div>

    @forelse ($tabla['secciones'] as $i => $seccion)
        <div class="{{ $seccion['salto'] && $i > 0 ? 'salto' : '' }}">
            @if ($seccion['titulo'])
                <div class="seccion">{{ $seccion['titulo'] }}</div>
            @endif
            <table class="tabla">
                <thead>
                    <tr>
                        @foreach ($seccion['columnas'] as [$columna, $alinear])
                            <th class="{{ $alinear === 'der' ? 'derecha' : '' }}">{{ $columna }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($seccion['filas'] as $fila)
                        <tr>
                            @foreach ($fila as $j => $valor)
                                <td class="{{ ($seccion['columnas'][$j][1] ?? 'izq') === 'der' ? 'derecha' : '' }}">{{ $valor ?? '' }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($seccion['columnas']) }}" class="apagado">Sin datos para este período.</td></tr>
                    @endforelse
                    @if ($seccion['totales'])
                        <tr class="fila-total">
                            @foreach ($seccion['totales'] as $j => $valor)
                                <td class="{{ ($seccion['columnas'][$j][1] ?? 'izq') === 'der' ? 'derecha' : '' }}">{{ $valor ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    @empty
        <p class="apagado">Sin datos para este período.</p>
    @endforelse
</body>
</html>
