<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Cotización N° {{ $cotizacion->code }}</title>
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
            font-size: 11px;
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

        @if ($cotizacion->status === 'anulada')
            <div class="caja-anulado">COTIZACIÓN ANULADA</div>
        @elseif ($cotizacion->status === 'vencida')
            <div class="caja-anulado">COTIZACIÓN VENCIDA</div>
        @endif

        {{-- ══════════════════ HEADER ══════════════════ --}}
        <table style="width:100%;">
            <tr>
                <td style="width:170px; vertical-align:top;">
                    @if (!empty($logo))
                        <img src="{{ $logo }}" style="max-width:170px; max-height:70px;">
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
                                <div class="tipo">Cotización</div>
                                <div class="numero">{{ $cotizacion->code }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- ══════════════════ CLIENTE / COTIZACIÓN ══════════════════ --}}
        <table style="width:100%; margin-top:16px;">
            <tr>
                <td class="info-box" style="width:60%;">
                    <div class="titulo">DATOS DEL CLIENTE</div>
                    <table class="info-grid">
                        @if ($cotizacion->client)
                            <tr>
                                <td style="width:110px;">{{ $cotizacion->client->type_document }}</td>
                                <td>: {{ $cotizacion->client->n_document }}</td>
                            </tr>
                            <tr>
                                <td>DENOMINACIÓN</td>
                                <td>: {{ $cotizacion->client->full_name }}</td>
                            </tr>
                        @else
                            <tr>
                                <td style="width:110px;">NOMBRE</td>
                                <td>: {{ $cotizacion->client_name_free ?? '-' }}</td>
                            </tr>
                            <tr>
                                <td>TELÉFONO</td>
                                <td>: {{ $cotizacion->client_phone_free ?? '-' }}</td>
                            </tr>
                        @endif
                    </table>
                </td>
                <td style="width:12px;"></td>
                <td class="info-box" style="width:40%;">
                    <div class="titulo">DATOS DE LA COTIZACIÓN</div>
                    <table class="info-grid">
                        <tr>
                            <td style="width:110px;">FECHA</td>
                            <td>: {{ $cotizacion->created_at->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <td>VÁLIDA HASTA</td>
                            <td>: {{ $cotizacion->valid_until?->format('d/m/Y') ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td>MONEDA</td>
                            <td>: {{ $cotizacion->currency }}</td>
                        </tr>
                        <tr>
                            <td>VENDEDOR</td>
                            <td>: {{ $cotizacion->registradoPor->name ?? '' }} {{ $cotizacion->registradoPor->surname ?? '' }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- ══════════════════ TABLA DE ÍTEMS ══════════════════ --}}
        <table class="items">
            <colgroup>
                <col style="width:50px;">
                <col>
                <col style="width:80px;">
                <col style="width:90px;">
                <col style="width:70px;">
                <col style="width:90px;">
            </colgroup>
            <thead>
                <tr>
                    <th class="right">CANT.</th>
                    <th>DESCRIPCIÓN</th>
                    <th class="right">P. UNIT.</th>
                    <th class="right">SUBTOTAL</th>
                    <th class="right">DESC. %</th>
                    <th class="right">TOTAL LÍNEA</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cotizacion->items as $item)
                    <tr>
                        <td class="right">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</td>
                        <td>{{ $item->product->title ?? $item->description }}</td>
                        <td class="right">{{ number_format($item->unit_price, 2) }}</td>
                        <td class="right">{{ number_format($item->quantity * $item->unit_price, 2) }}</td>
                        <td class="right">{{ number_format($item->discount_percent, 2) }}</td>
                        <td class="right">{{ number_format($item->subtotal, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- ══════════════════ TOTALES ══════════════════ --}}
        <table class="totales">
            <tr>
                <td>SUBTOTAL</td>
                <td class="moneda">{{ $cotizacion->currency }}</td>
                <td class="valor">{{ number_format($cotizacion->subtotal, 2) }}</td>
            </tr>
            @if ($cotizacion->discount_global > 0)
                <tr>
                    <td>DESCUENTO</td>
                    <td class="moneda">{{ $cotizacion->currency }}</td>
                    <td class="valor">-{{ number_format($cotizacion->discount_global, 2) }}</td>
                </tr>
            @endif
            <tr class="total-final">
                <td>TOTAL</td>
                <td class="moneda">{{ $cotizacion->currency }}</td>
                <td class="valor">{{ number_format($cotizacion->total, 2) }}</td>
            </tr>
        </table>

        @if ($cotizacion->observacion)
            <div class="footer-legal" style="margin-top:16px;">
                <strong>OBSERVACIONES:</strong> {{ $cotizacion->observacion }}
            </div>
        @endif

        {{-- ══════════════════ LEYENDA ══════════════════ --}}
        <div class="caja-redonda">
            <strong>DOCUMENTO SIN VALOR TRIBUTARIO — NO ES UN COMPROBANTE DE PAGO.</strong><br>
            Precios sujetos a confirmación de stock al momento de aceptar la cotización.
        </div>

    </div>
</body>

</html>
