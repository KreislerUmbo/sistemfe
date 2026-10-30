<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        {{-- T-006 — mismo membrete fijo, fuente y estilo que la cotización. --}}
        @include('pdf.agencia-viajes.partials.membrete-estilos')

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #111111;
            margin: 0;
        }

        table {
            border-collapse: collapse;
        }

        .documento {
            border: 1px solid #999999;
            padding: 24px;
        }

        .titulo-doc {
            font-family: 'Poppins-SemiBold', 'Poppins', sans-serif;
            font-size: 17px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 0 0 4px;
        }

        .subtitulo-doc {
            font-size: 11px;
            color: #444444;
            padding-bottom: 12px;
            margin-bottom: 8px;
            border-bottom: 1px solid #dddddd;
        }

        .datos {
            width: 100%;
            margin-bottom: 6px;
        }

        .datos td {
            font-size: 11px;
            padding: 2px 0;
        }

        .datos .etiqueta {
            width: 110px;
            color: #555555;
        }

        .seccion-titulo-mod {
            font-family: 'Poppins-SemiBold', 'Poppins', sans-serif;
            font-weight: bold;
            font-size: 12px;
            margin: 14px 0 4px;
            padding-bottom: 2px;
            border-bottom: 1px solid #999999;
            page-break-after: avoid;
        }

        .tabla {
            width: 100%;
        }

        .tabla th {
            font-family: 'Poppins-SemiBold', 'Poppins', sans-serif;
            font-size: 10px;
            text-align: left;
            border-bottom: 1px solid #999999;
            padding: 3px 4px;
            background: #f2f2f2;
        }

        .tabla td {
            font-size: 11px;
            padding: 4px;
            border-bottom: 1px solid #eeeeee;
            vertical-align: top;
        }

        .der {
            text-align: right !important;
        }

        .totales {
            width: 55%;
            margin-left: 45%;
            margin-top: 8px;
        }

        .totales td {
            font-size: 11px;
            padding: 3px 4px;
            border-bottom: 1px solid #eeeeee;
        }

        .totales .fuerte td {
            font-family: 'Poppins-SemiBold', 'Poppins', sans-serif;
            font-weight: bold;
            border-top: 1px solid #999999;
        }

        .nota {
            font-size: 10px;
            color: #555555;
            margin-top: 10px;
        }
    </style>
</head>

@php
    $m = fn (int $centavos) => ($centavos < 0 ? '-' : '').$simbolo.' '.number_format(abs($centavos) / 100, 2);
@endphp

<body>
    @include('pdf.agencia-viajes.partials.membrete-header')

    <div class="documento">

        <div class="titulo-doc">{{ $titulo }}</div>
        <div class="subtitulo-doc">
            {{ $empresa->razon_social_comercial ?? $empresa->razon_social ?? '' }}
            @if (!empty($empresa?->n_document)) &nbsp;·&nbsp; RUC {{ $empresa->n_document }} @endif
        </div>

        <table class="datos">
            <tr>
                <td class="etiqueta">Reserva</td>
                <td>{{ $codigoReserva }}</td>
            </tr>
            @if ($cliente !== '')
                <tr>
                    <td class="etiqueta">Cliente</td>
                    <td>{{ $cliente }}</td>
                </tr>
            @endif
            <tr>
                <td class="etiqueta">Fecha de emisión</td>
                <td>{{ $emision }}</td>
            </tr>
            @if ($aplicadaEn)
                <tr>
                    <td class="etiqueta">Aplicada el</td>
                    <td>{{ $aplicadaEn }}</td>
                </tr>
            @endif
        </table>

        @if (count($lineas) > 0)
            <div class="seccion-titulo-mod">Servicios que cambian</div>
            <table class="tabla">
                <tr>
                    <th>Servicio</th>
                    <th>Cambio</th>
                    <th class="der">Vendido</th>
                    <th class="der">Nuevo</th>
                    <th class="der">Diferencia</th>
                </tr>
                @foreach ($lineas as $linea)
                    <tr>
                        <td>{{ $linea['descripcion'] }}</td>
                        <td>{{ $linea['accion'] }}</td>
                        <td class="der">{{ $m($linea['vendido_centavos']) }}</td>
                        <td class="der">{{ $m($linea['nuevo_centavos']) }}</td>
                        <td class="der">{{ $m($linea['diferencia_centavos']) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if (count($pasajeros) > 0)
            <div class="seccion-titulo-mod">Pasajeros</div>
            <table class="tabla">
                <tr>
                    <th>Pasajero</th>
                    <th>Tipo</th>
                    <th>Cambio</th>
                </tr>
                @foreach ($pasajeros as $pasajero)
                    <tr>
                        <td>{{ $pasajero['nombre'] }}</td>
                        <td>{{ $pasajero['tipo'] }}</td>
                        <td>{{ $pasajero['accion'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if (count($penalidades) > 0)
            <div class="seccion-titulo-mod">Penalidades</div>
            <table class="tabla">
                <tr>
                    <th>Concepto y motivo</th>
                    <th class="der">Monto</th>
                </tr>
                @foreach ($penalidades as $penalidad)
                    <tr>
                        <td>{{ $penalidad['descripcion'] }}</td>
                        <td class="der">{{ $m($penalidad['nuevo_centavos']) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        <div class="seccion-titulo-mod">Resumen ({{ $moneda }})</div>
        <table class="totales">
            <tr>
                <td>Total vendido</td>
                <td class="der">{{ $m($totales['vendido']) }}</td>
            </tr>
            <tr>
                <td>Total nuevo</td>
                <td class="der">{{ $m($totales['nuevo']) }}</td>
            </tr>
            <tr>
                <td>Diferencia</td>
                <td class="der">{{ $m($totales['diferencia']) }}</td>
            </tr>
            <tr>
                <td>Ya facturado</td>
                <td class="der">{{ $m($totales['ya_facturado']) }}</td>
            </tr>
            <tr>
                <td>Anticipos recibidos</td>
                <td class="der">{{ $m($totales['anticipos_aplicados']) }}</td>
            </tr>
            <tr class="fuerte">
                <td>Saldo</td>
                <td class="der">{{ $m($totales['saldo']) }}</td>
            </tr>
        </table>

        <div class="nota">Saldo = total nuevo menos los anticipos recibidos.</div>

    </div>

    @include('pdf.agencia-viajes.partials.membrete-footer')
</body>

</html>
