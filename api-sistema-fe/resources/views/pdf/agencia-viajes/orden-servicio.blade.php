<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Orden de servicio — {{ $orden['proveedor'] }}</title>
    {{-- Fila 13, fase 2c (D14): orden de servicio de un proveedor para una salida.
         Datos mínimos (Ley 29733): cantidades y alimentación especial sin nombres;
         al transportista, reservas con titular y pasajeros, sin documentos. --}}
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #111111; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        .marca { position: fixed; top: -10mm; right: 0; font-size: 8px; color: #999999; }
        .header { border-bottom: 1px solid #111111; padding-bottom: 10px; margin-bottom: 14px; }
        .empresa-nombre { font-size: 14px; font-weight: bold; }
        .titulo { font-size: 16px; font-weight: bold; margin: 0 0 4px; }
        .aviso { border: 1px solid #c58a00; background: #fff6df; padding: 6px 8px; margin-bottom: 12px; }
        .seccion { font-size: 12px; font-weight: bold; margin: 14px 0 6px; text-transform: uppercase; color: #333333; }
        .tabla th, .tabla td { border: 1px solid #bbbbbb; padding: 5px 6px; text-align: left; }
        .tabla th { background: #f0f0f0; }
        .num { text-align: right; }
        .pie { margin-top: 24px; font-size: 10px; color: #555555; }
    </style>
</head>

<body>
    <div class="marca">Generado el {{ $generadoEn }}</div>

    <table class="header">
        <tr>
            <td style="width:180px; vertical-align:top;">
                @if (!empty($logoUrl))
                    <img src="{{ $logoUrl }}" style="max-width:170px; max-height:60px;">
                @endif
            </td>
            <td style="vertical-align:top; text-align:right;">
                <div class="empresa-nombre">{{ $empresa->razon_social_comercial ?? $empresa->razon_social ?? '' }}</div>
                <div>RUC: {{ $empresa->n_document ?? '-' }} · Teléfono: {{ $empresa->phone ?? '-' }}</div>
            </td>
        </tr>
    </table>

    <div class="titulo">Orden de servicio</div>
    <table style="margin-bottom:12px;">
        <tr>
            <td><strong>Proveedor:</strong> {{ $orden['proveedor'] }}</td>
            <td><strong>Tour:</strong> {{ $salida->tourOrigen?->nombre }}</td>
        </tr>
        <tr>
            <td><strong>Fecha:</strong> {{ $salida->fecha->format('d/m/Y') }}{{ $salida->hora ? ' · ' . substr((string) $salida->hora, 0, 5) : '' }}</td>
            <td><strong>Estado de la salida:</strong> {{ ucfirst($salida->estado) }}</td>
        </tr>
        @if (!empty($salida->lugar_recojo))
            <tr>
                <td colspan="2"><strong>Recojo:</strong> {{ $salida->lugar_recojo }}</td>
            </tr>
        @endif
    </table>

    @if ($salida->estado === 'programada')
        <div class="aviso">La salida todavía está por confirmar.</div>
    @endif

    @if (!empty($orden['servicios']))
        <div class="seccion">Servicios</div>
        <table class="tabla">
            <tr><th>Servicio</th><th class="num" style="width:90px">Cantidad</th><th>Notas</th></tr>
            @foreach ($orden['servicios'] as $sv)
                <tr>
                    <td>{{ $sv['nombre'] }}</td>
                    <td class="num">{{ $sv['cantidad'] }}</td>
                    <td>{{ $sv['notas'] ?? '' }}</td>
                </tr>
            @endforeach
        </table>

        @php($p = $orden['pasajeros'])
        <div class="seccion">Pasajeros</div>
        <table class="tabla">
            <tr><th>Adultos</th><th>Niños</th><th>Infantes</th></tr>
            <tr>
                <td>{{ $p['adultos'] }}{{ $p['adultos_extranjeros'] ? ' (' . $p['adultos_extranjeros'] . ' extranjero(s))' : '' }}</td>
                <td>{{ $p['ninos'] }}{{ $p['ninos_extranjeros'] ? ' (' . $p['ninos_extranjeros'] . ' extranjero(s))' : '' }}</td>
                <td>{{ $p['infantes'] }}</td>
            </tr>
        </table>

        @if (!empty($orden['alimentacion_especial']))
            <div class="seccion">Alimentación especial</div>
            <table class="tabla">
                <tr><th>Tipo</th><th class="num" style="width:90px">Cantidad</th></tr>
                @foreach ($orden['alimentacion_especial'] as $tipo => $n)
                    <tr><td>{{ $tipo }}</td><td class="num">{{ $n }}</td></tr>
                @endforeach
            </table>
        @endif
    @endif

    @foreach ($orden['vehiculos'] as $v)
        <div class="seccion">Vehículo: {{ $v['descripcion'] }}{{ $v['chofer'] ? ' — chofer ' . $v['chofer'] : '' }}{{ $v['capacidad'] ? ' — capacidad ' . $v['capacidad'] : '' }}</div>
        <table class="tabla">
            <tr><th>Reserva</th><th>Titular</th><th class="num">Pasajeros</th><th class="num">Infantes</th><th>Necesidades especiales</th></tr>
            @forelse ($v['reservas'] as $r)
                <tr>
                    <td>{{ $r['codigo'] }}</td>
                    <td>{{ $r['titular'] ?? '' }}</td>
                    <td class="num">{{ $r['pasajeros'] }}</td>
                    <td class="num">{{ $r['infantes'] }}</td>
                    <td>{{ $r['con_discapacidad'] ? $r['con_discapacidad'] . ' pasajero(s)' : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Sin reservas asignadas a este vehículo.</td></tr>
            @endforelse
        </table>
    @endforeach

    <div class="pie">Por favor, confirme la recepción de esta orden. Datos de pasajeros usados solo para prestar el servicio.</div>
</body>

</html>
