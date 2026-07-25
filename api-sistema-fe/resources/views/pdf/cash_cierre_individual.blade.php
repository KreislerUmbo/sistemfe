<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>{{ $esPreview ? 'Vista previa de corte' : 'Cierre de caja' }} — Sesión #{{ $sessionData['id'] }}</title>
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

        .doc-box .numero {
            font-size: 14px;
            font-weight: bold;
            margin-top: 4px;
            font-family: 'Courier New', monospace;
        }

        .info-box {
            border: 1px solid #999999;
            padding: 10px 12px;
            font-size: 12px;
            vertical-align: top;
        }

        .info-box .titulo {
            font-weight: bold;
            margin-bottom: 6px;
        }

        .info-grid td {
            font-size: 12px;
            padding: 1px 0;
            vertical-align: top;
        }

        .items {
            width: 100%;
            margin-top: 16px;
        }

        .items th {
            background: #e6e6e6;
            border: 1px solid #999999;
            font-size: 11px;
            font-weight: bold;
            padding: 8px 6px;
            text-align: left;
        }

        .items td {
            border-left: 1px solid #999999;
            border-right: 1px solid #999999;
            border-top: 1px solid #eeeeee;
            font-size: 10px;
            padding: 6px;
        }

        .items tfoot td {
            border-bottom: 1px solid #999999;
        }

        .right {
            text-align: right;
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

        .totales .moneda {
            width: 30px;
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

        .caja-preview {
            margin-bottom: 14px;
            border: 2px solid #8a6d00;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 12px;
            font-weight: bold;
            text-align: center;
            color: #8a6d00;
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

        @if ($esPreview)
            <div class="caja-preview">
                VISTA PREVIA — SESIÓN AÚN ABIERTA (equivale al "corte X", no es el cierre definitivo).
            </div>
        @endif

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
                <td style="width:220px; vertical-align:top;">
                    <table class="doc-box">
                        <tr>
                            <td>
                                <div class="tipo">{{ $esPreview ? 'Vista previa de corte' : 'Cierre de caja' }}</div>
                                <div class="numero">SESIÓN #{{ $sessionData['id'] }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- ══════════════════ DATOS DE LA SESIÓN ══════════════════ --}}
        <table style="width:100%; margin-top:16px;">
            <tr>
                <td class="info-box" style="width:50%;">
                    <div class="titulo">DATOS DE LA SESIÓN</div>
                    <table class="info-grid">
                        <tr>
                            <td style="width:110px;">SEDE</td>
                            <td>: {{ $sessionData['cash_register']->branch->name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td>CAJA</td>
                            <td>: {{ $sessionData['cash_register']->name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td>CAJERO</td>
                            <td>: {{ $sessionData['opened_by_user']->name ?? '-' }} {{ $sessionData['opened_by_user']->surname ?? '' }}</td>
                        </tr>
                        <tr>
                            <td>APERTURA</td>
                            <td>: {{ optional($sessionData['opened_at'])->format('d/m/Y H:i') ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td>CIERRE</td>
                            <td>: {{ $sessionData['closed_at'] ? $sessionData['closed_at']->format('d/m/Y H:i') : 'En curso' }}</td>
                        </tr>
                        @if ($sessionData['closed_by_user'])
                            <tr>
                                <td>CERRADO POR</td>
                                <td>: {{ $sessionData['closed_by_user']->name }} {{ $sessionData['closed_by_user']->surname }}</td>
                            </tr>
                        @endif
                    </table>
                </td>
                <td style="width:12px;"></td>
                <td class="info-box" style="width:50%;">
                    <div class="titulo">FONDO Y RESULTADO</div>
                    <table class="info-grid">
                        <tr>
                            <td style="width:150px;">FONDO INICIAL</td>
                            <td>: S/ {{ number_format($sessionData['opening_amount'], 2) }}
                                @if ($sessionData['opening_amount_adjusted'])
                                    (ajustado)
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>{{ $esPreview ? 'ESPERADO (EN VIVO)' : 'EFECTIVO ESPERADO' }}</td>
                            <td>: S/ {{ number_format($sessionData['expected_cash_live'], 2) }}</td>
                        </tr>
                        @unless ($esPreview)
                            <tr>
                                <td>EFECTIVO CONTADO</td>
                                <td>: S/ {{ number_format($sessionData['counted_cash'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>DIFERENCIA</td>
                                <td>: S/ {{ number_format($sessionData['difference'], 2) }}</td>
                            </tr>
                            @if ($sessionData['difference_reason'])
                                <tr>
                                    <td>MOTIVO</td>
                                    <td>: {{ $sessionData['difference_reason'] }}</td>
                                </tr>
                            @endif
                        @endunless
                    </table>
                </td>
            </tr>
        </table>

        {{-- ══════════════════ TOTALES POR MÉTODO DE PAGO ══════════════════ --}}
        <table class="items" style="margin-top:20px;">
            <colgroup>
                <col>
                <col style="width:130px;">
            </colgroup>
            <thead>
                <tr>
                    <th>MÉTODO DE PAGO</th>
                    <th class="right">TOTAL NETO</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sessionData['totals_by_payment_method'] as $totalMetodo)
                    <tr>
                        <td>{{ $totalMetodo['payment_method_name'] }}</td>
                        <td class="right">S/ {{ number_format($totalMetodo['total'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- ══════════════════ DETALLE DE MOVIMIENTOS ══════════════════ --}}
        <table class="items" style="margin-top:20px;">
            <colgroup>
                <col style="width:110px;">
                <col style="width:90px;">
                <col style="width:90px;">
                <col style="width:70px;">
                <col>
            </colgroup>
            <thead>
                <tr>
                    <th>TIPO</th>
                    <th>MÉTODO</th>
                    <th class="right">MONTO</th>
                    <th>ESTADO</th>
                    <th>DESCRIPCIÓN / CONTRAPARTE</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sessionData['movements'] as $movimiento)
                    <tr>
                        <td>{{ $movimiento->type }}</td>
                        <td>{{ $movimiento->paymentMethod->name ?? '-' }}</td>
                        <td class="right">
                            {{ $movimiento->direction === 'in' ? '+' : '-' }}{{ number_format($movimiento->amount, 2) }}
                        </td>
                        <td>{{ $movimiento->status }}</td>
                        <td>
                            {{ $movimiento->description ?? $movimiento->concept->name ?? '-' }}
                            @if ($movimiento->counterparty_name)
                                — {{ $movimiento->counterparty_name }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- ══════════════════ DENOMINACIONES (SI HAY) ══════════════════ --}}
        @if (count($sessionData['denominations']) > 0)
            <table class="items" style="margin-top:20px;">
                <colgroup>
                    <col style="width:120px;">
                    <col style="width:90px;">
                    <col style="width:120px;">
                </colgroup>
                <thead>
                    <tr>
                        <th>DENOMINACIÓN</th>
                        <th class="right">CANTIDAD</th>
                        <th class="right">SUBTOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sessionData['denominations'] as $denominacion)
                        <tr>
                            <td>S/ {{ number_format($denominacion->denomination, 2) }}</td>
                            <td class="right">{{ $denominacion->quantity }}</td>
                            <td class="right">S/ {{ number_format($denominacion->subtotal, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($sessionData['closing_notes'])
            <div class="caja-redonda compacta" style="text-align:left;">
                <strong>NOTAS DE CIERRE:</strong> {{ $sessionData['closing_notes'] }}
            </div>
        @endif

        {{-- ══════════════════ LEYENDA ══════════════════ --}}
        <div class="caja-redonda">
            <strong>DOCUMENTO INTERNO DE CONTROL — NO ES COMPROBANTE DE PAGO SUNAT.</strong>
        </div>

        <div class="footer-legal">
            Reporte de caja — módulo de Caja.
        </div>

    </div>
</body>

</html>
