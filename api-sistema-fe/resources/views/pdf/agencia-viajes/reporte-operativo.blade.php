<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Reporte Operativo {{ $fechaDesde }} a {{ $fechaHasta }}</title>
    <style>
        @page {
            margin: 12mm 10mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #111111;
            margin: 0;
        }

        table {
            border-collapse: collapse;
        }

        .documento {
            border: 1px solid #999999;
            padding: 18px;
        }

        /* ── Marca de generación (Sesión 11d) ──────────────────────
           position:fixed dentro del margen de @page se repite en CADA página en
           DomPDF (técnica estándar del motor) — arriba-derecha, lejos del rango de
           fechas del reporte (título, a la izquierda) para no confundirse con él. */
        .marca-generacion {
            position: fixed;
            top: -10mm;
            right: 0;
            font-size: 8px;
            color: #999999;
            text-align: right;
        }

        /* ── Header ─────────────────────────────────────────────── */
        .header-wrap {
            border-bottom: 1px solid #111111;
            padding-bottom: 12px;
        }

        .logo-box {
            width: 170px;
            height: 60px;
            border: 1px dashed #cccccc;
            text-align: center;
            color: #999999;
            font-size: 11px;
            padding-top: 24px;
        }

        .empresa-nombre {
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
        }

        .empresa-datos {
            font-size: 11px;
            line-height: 1.5;
        }

        .titulo-doc {
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 14px 0 4px;
        }

        .subtitulo-doc {
            font-size: 12px;
            color: #444444;
            margin-bottom: 10px;
        }

        /* ── Por fecha ──────────────────────────────────────────── */
        .fecha-titulo {
            font-weight: bold;
            font-size: 13px;
            text-transform: uppercase;
            background: #e6e6e6;
            border: 1px solid #999999;
            padding: 5px 8px;
            margin-top: 14px;
        }

        /* ── Por grupo (Tour / Servicios sueltos) ──────────────────── */
        .grupo-titulo {
            font-weight: bold;
            font-size: 11px;
            background: #f2f2f2;
            border: 1px solid #999999;
            border-top: none;
            padding: 4px 8px;
        }

        .grupo-titulo.sin-guia {
            color: #b45309;
        }

        .items {
            width: 100%;
            margin-top: 0;
        }

        .items th {
            background: #f2f2f2;
            border: 1px solid #999999;
            font-size: 10px;
            font-weight: bold;
            padding: 5px 4px;
            text-align: left;
        }

        .items td {
            border-left: 1px solid #999999;
            border-right: 1px solid #999999;
            border-bottom: 1px solid #eeeeee;
            font-size: 10px;
            padding: 4px;
            vertical-align: top;
        }

        .items tr:last-child td {
            border-bottom: 1px solid #999999;
        }

        .sin-guia {
            color: #b45309;
            font-weight: bold;
        }

        .sin-datos {
            color: #999999;
            font-style: italic;
            text-align: center;
            padding: 10px;
            border: 1px solid #999999;
        }
    </style>
</head>

<body>
    <div class="marca-generacion">
        Generado por {{ $generadoPor }}<br>
        {{ $generadoEn }}
    </div>

    <div class="documento">

        {{-- ══════════════════ HEADER ══════════════════ --}}
        <table style="width:100%;" class="header-wrap">
            <tr>
                <td style="width:170px; vertical-align:top;">
                    @if (!empty($logoUrl))
                        <img src="{{ $logoUrl }}" style="max-width:170px; max-height:60px;">
                    @else
                        <div class="logo-box">LOGO</div>
                    @endif
                </td>
                <td style="vertical-align:top; padding:0 16px;">
                    <div class="empresa-nombre">{{ $empresa->razon_social_comercial ?? $empresa->razon_social ?? '' }}</div>
                    <div class="empresa-datos">
                        RUC: {{ $empresa->n_document ?? '-' }}<br>
                        Teléfono: {{ $empresa->phone ?? '-' }} &nbsp;·&nbsp; Email: {{ $empresa->email ?? '-' }}
                    </div>
                </td>
            </tr>
        </table>

        <div class="titulo-doc">Reporte Operativo</div>
        <div class="subtitulo-doc">
            @if ($fechaDesde === $fechaHasta)
                {{ \Illuminate\Support\Carbon::parse($fechaDesde)->translatedFormat('d \d\e F \d\e Y') }}
            @else
                {{ \Illuminate\Support\Carbon::parse($fechaDesde)->translatedFormat('d/m/Y') }}
                al
                {{ \Illuminate\Support\Carbon::parse($fechaHasta)->translatedFormat('d/m/Y') }}
            @endif
        </div>

        @forelse ($dias as $dia)
            <div class="fecha-titulo">{{ \Illuminate\Support\Carbon::parse($dia['fecha'])->translatedFormat('l d \d\e F') }}</div>

            @foreach ($dia['grupos'] as $grupo)
                <div class="grupo-titulo {{ ($grupo['es_tour'] && !$grupo['guia']) ? 'sin-guia' : '' }}">
                    {{ $grupo['nombre'] }}
                    @if ($grupo['es_tour'])
                        — Guía:
                        @if ($grupo['guia'])
                            {{ $grupo['guia']['nombre'] }}{{ $grupo['vehiculo'] ? ' · ' . $grupo['vehiculo'] : '' }}
                        @else
                            Sin asignar
                        @endif
                    @endif
                </div>
                <table class="items">
                    <thead>
                        <tr>
                            <th style="width:9%">Documento</th>
                            <th style="width:6%">Tipo pax</th>
                            <th style="width:13%">Pasajero</th>
                            <th style="width:11%">Hotel</th>
                            <th style="width:10%">Alimentación / discapacidad</th>
                            <th style="width:6%">Hora</th>
                            <th style="width:15%">Servicio</th>
                            <th style="width:10%">Destino</th>
                            @unless ($grupo['es_tour'])
                                <th style="width:10%">Guía</th>
                            @endunless
                            <th style="width:6%">Check-in</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grupo['pasajeros'] as $pax)
                            @php $totalFilasPax = count($pax['filas']); @endphp
                            @foreach ($pax['filas'] as $i => $filaServicio)
                                <tr>
                                    @if ($i === 0)
                                        <td rowspan="{{ $totalFilasPax }}">{{ $pax['pasajero']['documento'] }}</td>
                                        <td rowspan="{{ $totalFilasPax }}">{{ $pax['pasajero']['tipo_pax'] }}</td>
                                        <td rowspan="{{ $totalFilasPax }}">{{ $pax['pasajero']['nombre_display'] }}</td>
                                        <td rowspan="{{ $totalFilasPax }}">{{ $pax['pasajero']['hotel_del_dia'] ?? '—' }}</td>
                                        <td rowspan="{{ $totalFilasPax }}">
                                            {{ $pax['pasajero']['alimentacion_especial'] ?: '-' }}
                                            @if ($pax['pasajero']['discapacidad'])
                                                <br><span style="color:#b45309;">Discapacidad: {{ $pax['pasajero']['discapacidad'] }}</span>
                                            @endif
                                        </td>
                                    @endif
                                    <td>{{ $filaServicio['hora'] }}</td>
                                    <td>{{ $filaServicio['servicio'] }}</td>
                                    <td>{{ $filaServicio['destino'] }}</td>
                                    @unless ($grupo['es_tour'])
                                        <td class="{{ $filaServicio['sin_guia'] ? 'sin-guia' : '' }}">
                                            {{ $filaServicio['guia']['nombre'] ?? ($filaServicio['sin_guia'] ? 'Sin asignar' : '—') }}
                                        </td>
                                    @endunless
                                    <td>{{ $filaServicio['checkin'] === null ? '—' : ($filaServicio['checkin'] ? 'Sí' : 'No') }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        @empty
            <div class="sin-datos">Sin servicios programados en este rango de fechas.</div>
        @endforelse
    </div>
</body>

</html>
