<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Reporte de caja — {{ $dateFrom }} a {{ $dateTo }}</title>
    <style>
        @page {
            margin: 15mm 12mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
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

        .header-wrap {
            border-bottom: 1px solid #111111;
            padding-bottom: 16px;
        }

        .empresa-nombre {
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
        }

        .empresa-datos {
            font-size: 12px;
            line-height: 1.5;
        }

        .doc-box {
            width: 100%;
            border: 1px solid #111111;
        }

        .doc-box td {
            text-align: center;
            padding: 10px 8px;
        }

        .doc-box .tipo {
            font-size: 15px;
            font-weight: bold;
            margin-top: 6px;
            text-transform: uppercase;
        }

        .doc-box .rango {
            font-size: 12px;
            font-weight: bold;
            margin-top: 4px;
        }

        .items {
            width: 100%;
            margin-top: 16px;
        }

        .items th {
            background: #e6e6e6;
            border: 1px solid #999999;
            font-size: 10px;
            font-weight: bold;
            padding: 6px 4px;
            text-align: left;
        }

        .items td {
            border-left: 1px solid #999999;
            border-right: 1px solid #999999;
            border-top: 1px solid #eeeeee;
            font-size: 10px;
            padding: 5px 4px;
        }

        .items tfoot td {
            border-bottom: 1px solid #999999;
        }

        .right {
            text-align: right;
        }

        .estado-abierta {
            color: #8a6d00;
            font-weight: bold;
        }

        .totales {
            width: 280px;
            margin-left: auto;
            margin-top: 14px;
            font-size: 12px;
        }

        .totales td {
            padding: 2px 0;
        }

        .totales .valor {
            text-align: right;
            width: 90px;
        }

        .totales .total-final td {
            font-weight: bold;
            border-top: 1px solid #111111;
            padding-top: 5px;
        }

        .caja-redonda {
            margin-top: 14px;
            border: 1px solid #999999;
            border-radius: 14px;
            padding: 10px 16px;
            font-size: 12px;
            text-align: center;
        }

        .footer-legal {
            border: 1px solid #999999;
            padding: 10px 14px;
            font-size: 11px;
            line-height: 1.6;
            margin-top: 16px;
        }
    </style>
</head>

<body>
    <div class="documento">

        {{-- ══════════════════ HEADER ══════════════════ --}}
        <table style="width:100%;" class="header-wrap">
            <tr>
                <td style="vertical-align:top; padding:0 16px 0 0;">
                    <div class="empresa-nombre">{{ $empresa->razon_social_comercial ?? $empresa->razon_social }}</div>
                    <div class="empresa-datos">
                        {{ $empresa->address }}<br>
                        {{ $empresa->distrito }} - {{ $empresa->provincia }} - {{ $empresa->region }}<br>
                        Teléfono: {{ $empresa->phone }} &nbsp;·&nbsp; Email: {{ $empresa->email }}
                    </div>
                </td>
                <td style="width:240px; vertical-align:top;">
                    <table class="doc-box">
                        <tr>
                            <td>
                                <div class="tipo">Reporte consolidado de caja</div>
                                <div class="rango">
                                    {{ \Carbon\Carbon::parse($dateFrom)->format('d/m/Y') }}
                                    al
                                    {{ \Carbon\Carbon::parse($dateTo)->format('d/m/Y') }}
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- ══════════════════ SESIONES DEL RANGO ══════════════════ --}}
        <table class="items" style="margin-top:20px;">
            <colgroup>
                <col style="width:80px;">
                <col style="width:80px;">
                <col style="width:90px;">
                <col style="width:70px;">
                <col style="width:90px;">
                <col style="width:60px;">
                <col style="width:60px;">
                <col style="width:60px;">
                <col style="width:60px;">
                <col style="width:60px;">
            </colgroup>
            <thead>
                <tr>
                    <th>APERTURA</th>
                    <th>CIERRE</th>
                    <th>SEDE</th>
                    <th>CAJA</th>
                    <th>CAJERO</th>
                    <th class="right">FONDO</th>
                    <th class="right">ESPERADO</th>
                    <th class="right">CONTADO</th>
                    <th class="right">DIFER.</th>
                    <th>ESTADO</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sessionsConTotales as $item)
                    @php $sesion = $item['session']; @endphp
                    <tr>
                        <td>{{ $sesion->opened_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $sesion->closed_at ? $sesion->closed_at->format('d/m/Y H:i') : '—' }}</td>
                        <td>{{ $sesion->cashRegister->branch->name ?? '-' }}</td>
                        <td>{{ $sesion->cashRegister->name ?? '-' }}</td>
                        <td>{{ $sesion->openedByUser->name ?? '-' }}</td>
                        <td class="right">{{ number_format($sesion->opening_amount, 2) }}</td>
                        <td class="right">{{ number_format($sesion->expected_cash ?? 0, 2) }}</td>
                        <td class="right">{{ number_format($sesion->counted_cash ?? 0, 2) }}</td>
                        <td class="right">{{ number_format($sesion->difference ?? 0, 2) }}</td>
                        <td class="{{ $sesion->status === 'open' ? 'estado-abierta' : '' }}">
                            {{ $sesion->status === 'open' ? 'ABIERTA' : 'CERRADA' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (count($sessionsConTotales) === 0)
            <div class="caja-redonda">No hay sesiones registradas en el rango seleccionado.</div>
        @endif

        {{-- ══════════════════ TOTALES GENERALES POR MÉTODO DE PAGO ══════════════════ --}}
        <table class="items" style="margin-top:20px;">
            <colgroup>
                <col>
                <col style="width:130px;">
            </colgroup>
            <thead>
                <tr>
                    <th>MÉTODO DE PAGO (TOTAL DEL RANGO)</th>
                    <th class="right">TOTAL NETO</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($totalesGenerales as $totalMetodo)
                    <tr>
                        <td>{{ $totalMetodo['payment_method_name'] }}</td>
                        <td class="right">S/ {{ number_format($totalMetodo['total'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2">Sin movimientos confirmados en el rango.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="caja-redonda">
            <strong>DOCUMENTO INTERNO DE CONTROL — NO ES COMPROBANTE DE PAGO SUNAT.</strong><br>
            Detalle de movimientos por sesión disponible en el reporte individual de cada sesión.
        </div>

        <div class="footer-legal">
            Reporte consolidado de caja — módulo de Caja. Rango máximo permitido: 1 mes.
        </div>

    </div>
</body>

</html>
