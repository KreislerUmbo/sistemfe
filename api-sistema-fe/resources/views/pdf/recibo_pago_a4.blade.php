<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Recibo de pago N° {{ $receipt->numero_recibo }}</title>
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

        /* ── Header ─────────────────────────────────────────────── */
        .header-wrap {
            border-bottom: 1px solid #111111;
            padding-bottom: 16px;
        }

        .logo-box {
            width: 170px;
            height: 70px;
            border: 1px dashed #cccccc;
            text-align: center;
            color: #999999;
            font-size: 11px;
            padding-top: 28px;
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

        /* ── Cliente / recibo ───────────────────────────────────── */
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

        /* ── Tabla de aplicaciones ──────────────────────────────── */
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
            font-size: 11px;
            padding: 6px;
        }

        .items tfoot td {
            border-bottom: 1px solid #999999;
        }

        .right {
            text-align: right;
        }

        /* ── Totales ────────────────────────────────────────────── */
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

        /* ── Cajas redondeadas ──────────────────────────────────── */
        .caja-redonda {
            margin-top: 14px;
            border: 1px solid #999999;
            border-radius: 14px;
            padding: 10px 16px;
            font-size: 12px;
            text-align: center;
        }

        .caja-redonda.compacta {
            padding: 8px 16px;
            font-size: 11px;
        }

        .caja-anulado {
            margin-bottom: 14px;
            border: 2px solid #b02a2a;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 12px;
            font-weight: bold;
            text-align: center;
            color: #b02a2a;
        }

        /* ── Footer ─────────────────────────────────────────────── */
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

        @if ($receipt->estado === 'anulado')
            <div class="caja-anulado">
                RECIBO ANULADO — Motivo: {{ $receipt->motivo_anulacion ?? '-' }}
                @if ($receipt->anulado_en)
                    — {{ $receipt->anulado_en->format('d/m/Y H:i') }}
                @endif
                @if ($receipt->anuladoPor)
                    — {{ $receipt->anuladoPor->name }} {{ $receipt->anuladoPor->surname }}
                @endif
            </div>
        @endif

        {{-- ══════════════════ HEADER ══════════════════ --}}
        <table style="width:100%;">
            <tr>
                <td style="width:170px; vertical-align:top;">
                    @if (!empty($empresa->logo))
                        <img src="{{ $empresa->logo }}" style="max-width:170px; max-height:70px;">
                    @else
                        <div class="logo-box">LOGO</div>
                    @endif
                </td>
                <td style="vertical-align:top; padding:0 16px;">
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
                                <div class="tipo">RECIBO DE PAGO</div>
                                <div class="numero">{{ $receipt->numero_recibo }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- ══════════════════ CLIENTE / RECIBO ══════════════════ --}}
        <table style="width:100%; margin-top:16px;">
            <tr>
                <td class="info-box" style="width:60%;">
                    <div class="titulo">DATOS DEL CLIENTE</div>
                    <table class="info-grid">
                        <tr>
                            <td style="width:110px;">{{ $receipt->client->type_document }}</td>
                            <td>: {{ $receipt->client->n_document }}</td>
                        </tr>
                        <tr>
                            <td>DENOMINACIÓN</td>
                            <td>: {{ $receipt->client->full_name }}</td>
                        </tr>
                        <tr>
                            <td>DIRECCIÓN</td>
                            <td>: {{ $receipt->client->address ?: '-' }}</td>
                        </tr>
                    </table>
                </td>
                <td style="width:12px;"></td>
                <td class="info-box" style="width:40%;">
                    <div class="titulo">DATOS DEL RECIBO</div>
                    <table class="info-grid">
                        <tr>
                            <td style="width:110px;">FECHA DE PAGO</td>
                            <td>: {{ $receipt->fecha_pago->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <td>MEDIO DE PAGO</td>
                            <td>: {{ $receipt->medio_pago }}</td>
                        </tr>
                        <tr>
                            <td>N° OPERACIÓN</td>
                            <td>: {{ $receipt->nro_operacion ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td>REGISTRADO POR</td>
                            <td>: {{ $receipt->registradoPor->name ?? '' }} {{ $receipt->registradoPor->surname ?? '' }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- ══════════════════ TABLA DE APLICACIONES ══════════════════ --}}
        <table class="items">
            <colgroup>
                <col>
                <col style="width:70px;">
                <col style="width:90px;">
                <col style="width:90px;">
                <col style="width:90px;">
            </colgroup>
            <thead>
                <tr>
                    <th>COMPROBANTE</th>
                    <th>CUOTA</th>
                    <th class="right">MONTO CAPITAL</th>
                    <th class="right">MORA COBRADA</th>
                    <th class="right">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($receipt->applications as $aplicacion)
                    <tr>
                        <td>
                            {{ $aplicacion->sale->n_operacion ?? "#{$aplicacion->sale_id} (sin emitir)" }}
                            @if ($aplicacion->estado === 'anulada')
                                <span style="color:#b02a2a;">(anulada)</span>
                            @elseif ($aplicacion->estado === 'trasladada')
                                <span style="color:#8a6d00;">(trasladada)</span>
                            @endif
                        </td>
                        <td>{{ $aplicacion->installment->numero_cuota ?? '—' }}</td>
                        <td class="right">{{ number_format($aplicacion->monto_aplicado, 2) }}</td>
                        <td class="right">{{ number_format($aplicacion->monto_mora_cobrado, 2) }}</td>
                        <td class="right">{{ number_format($aplicacion->monto_aplicado + $aplicacion->monto_mora_cobrado, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="height:1px; padding:0;"></td>
                </tr>
            </tfoot>
        </table>

        {{-- ══════════════════ TOTALES ══════════════════ --}}
        @php $resumen = $receipt->resumenImpresion(); @endphp
        <table class="totales">
            <tr>
                <td>CAPITAL APLICADO</td>
                <td class="moneda">S/</td>
                <td class="valor">{{ number_format($resumen['total_capital_aplicado'], 2) }}</td>
            </tr>
            @if ($resumen['total_mora_cobrada'] > 0)
                <tr>
                    <td>MORA COBRADA</td>
                    <td class="moneda">S/</td>
                    <td class="valor">{{ number_format($resumen['total_mora_cobrada'], 2) }}</td>
                </tr>
            @endif
            @if ($receipt->monto_no_aplicado > 0)
                <tr>
                    <td>EXCEDENTE (SALDO A FAVOR)</td>
                    <td class="moneda">S/</td>
                    <td class="valor">{{ number_format($receipt->monto_no_aplicado, 2) }}</td>
                </tr>
            @endif
            <tr class="total-final">
                <td>TOTAL PAGADO</td>
                <td class="moneda">S/</td>
                <td class="valor">{{ number_format($receipt->monto_total, 2) }}</td>
            </tr>
        </table>

        {{-- ══════════════════ SALDO ACTUAL DE VENTAS AFECTADAS ══════════════════ --}}
        <table class="items" style="margin-top:20px;">
            <colgroup>
                <col>
                <col style="width:130px;">
            </colgroup>
            <thead>
                <tr>
                    <th>VENTA AFECTADA</th>
                    <th class="right">SALDO ACTUAL</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resumen['ventas_afectadas'] as $venta)
                    <tr>
                        <td>{{ $venta['n_operacion'] ?? "#{$venta['sale_id']}" }}</td>
                        <td class="right">S/ {{ number_format($venta['saldo_pendiente_actual'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div style="font-size:10px; color:#666666; margin-top:4px;">
            Saldo referencial al momento de emitir este documento — puede variar si hubo pagos posteriores.
        </div>

        {{-- ══════════════════ LEYENDA ══════════════════ --}}
        <div class="caja-redonda">
            <strong>DOCUMENTO INTERNO DE CONTROL — NO ES COMPROBANTE DE PAGO SUNAT.</strong><br>
            No sustituye la boleta/factura electrónica de la venta original.
        </div>

        {{-- ══════════════════ FOOTER ══════════════════ --}}
        <div class="footer-legal">
            Recibo de pago interno — módulo de amortizaciones/ventas a crédito.
        </div>

    </div>
</body>

</html>
