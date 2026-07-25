<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Comprobante N° {{ $venta->id }}</title>
    <style>
        @page {
            margin: 2mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', monospace;
            font-size: 9px;
            color: #000;
            margin: 0;
            width: 76mm;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .bold {
            font-weight: bold;
        }

        .linea {
            border-top: 1px dashed #000;
            margin: 4px 0;
        }

        .titulo {
            font-size: 11px;
            font-weight: bold;
        }

        .items td {
            padding: 1px 0;
            vertical-align: top;
        }

        .items .desc {
            width: 100%;
        }

        .totales td {
            padding: 1px 0;
        }

        .qr-box {
            text-align: center;
            margin-top: 6px;
        }

        .qr-box img {
            width: 90px;
            height: 90px;
        }

        .hash {
            font-size: 6.5px;
            word-break: break-all;
        }

        .leyenda-amazonia {
            margin-top: 4px;
            font-size: 8px;
            font-weight: bold;
            text-align: center;
        }
    </style>
</head>

<body>

    <div class="center">
        <div class="titulo">{{ $empresa->razon_social_comercial ?? $empresa->razon_social }}</div>
        <div>RUC: {{ $empresa->n_document }}</div>
        <div>{{ $empresa->address }}</div>
        <div>{{ $empresa->distrito }}, {{ $empresa->provincia }}</div>
    </div>

    <div class="linea"></div>

    <div class="center bold">
        @if ($venta->state_sale != 1)
            COTIZACIÓN
        @elseif (str_starts_with($venta->serie, 'F'))
            FACTURA ELECTRÓNICA
        @else
            BOLETA DE VENTA ELECTRÓNICA
        @endif
    </div>
    <div class="center bold">
        {{ $venta->n_operacion ?? ($venta->serie . '-' . str_pad($venta->n_transaction, 8, '0', STR_PAD_LEFT)) }}
    </div>

    <div class="linea"></div>

    <div>Fecha: {{ \Carbon\Carbon::parse($venta->date)->format('d/m/Y H:i') }}</div>
    <div>Cliente: {{ $venta->client->full_name }}</div>
    <div>{{ $venta->client->type_document }}: {{ $venta->client->n_document }}</div>
    <div>Vendedor: {{ $venta->user->name }} {{ $venta->user->surname }}</div>

    <div class="linea"></div>

    <table class="items">
        @foreach ($venta->sale_details as $detalle)
            <tr>
                <td class="desc" colspan="2">{{ $detalle->product->title }}</td>
            </tr>
            <tr>
                <td>
                    {{ rtrim(rtrim(number_format($detalle->quantity, 2), '0'), '.') }}
                    {{ $detalle->unidad_medida }}
                    x {{ $venta->currency }} {{ number_format($detalle->price_final, 2) }}
                </td>
                <td class="right">{{ $venta->currency }} {{ number_format($detalle->subtotal, 2) }}</td>
            </tr>
        @endforeach
    </table>

    <div class="linea"></div>

    @php $resumen = $venta->resumenImpresion(); @endphp

    <table class="totales">
        <tr>
            <td>Subtotal</td>
            <td class="right">{{ $venta->currency }} {{ number_format($resumen['sub_total_sale'], 2) }}</td>
        </tr>
        @if ($venta->discount > 0 || $venta->discount_global > 0)
            <tr>
                <td>Descuento</td>
                <td class="right">-{{ $venta->currency }} {{ number_format($venta->discount + $venta->discount_global, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td>IGV</td>
            <td class="right">{{ $venta->currency }} {{ number_format($resumen['igv_total'], 2) }}</td>
        </tr>
        @if ($resumen['icbper_total'] > 0)
            <tr>
                <td>ICBPER</td>
                <td class="right">{{ $venta->currency }} {{ number_format($resumen['icbper_total'], 2) }}</td>
            </tr>
        @endif
        @if ($venta->retencion_igv == 1)
            <tr>
                <td>Retención</td>
                <td class="right">-{{ $venta->currency }} {{ number_format($resumen['retencion_igv_total'], 2) }}</td>
            </tr>
        @elseif ($venta->retencion_igv == 2)
            <tr>
                <td>Detracción</td>
                <td class="right">-{{ $venta->currency }} {{ number_format($resumen['retencion_igv_total'], 2) }}</td>
            </tr>
        @elseif ($venta->retencion_igv == 3)
            <tr>
                <td>Percepción</td>
                <td class="right">+{{ $venta->currency }} {{ number_format($resumen['percepcion_igv_total'], 2) }}</td>
            </tr>
        @endif
    </table>

    <div class="linea"></div>

    <table class="totales">
        <tr class="bold">
            <td>TOTAL</td>
            <td class="right">{{ $venta->currency }} {{ number_format($resumen['total_sales'], 2) }}</td>
        </tr>
        <tr>
            <td>Pagado</td>
            <td class="right">{{ $venta->currency }} {{ number_format($venta->paid_out, 2) }}</td>
        </tr>
        <tr>
            <td>Saldo</td>
            <td class="right">{{ $venta->currency }} {{ number_format($venta->debt, 2) }}</td>
        </tr>
    </table>

    @if ($venta->sale_payments->isNotEmpty())
        <div class="linea"></div>

        <div class="bold">FORMA DE PAGO</div>
        <table class="totales">
            @foreach ($venta->sale_payments->sortBy('date_payment') as $pago)
                <tr>
                    <td>{{ $pago->method_payment }} ({{ \Carbon\Carbon::parse($pago->date_payment)->format('d/m/Y') }})</td>
                    <td class="right">{{ $venta->currency }} {{ number_format($pago->amount, 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="linea"></div>

    <div>SON: {{ $resumen['monto_en_letras'] }}</div>

    @if ($resumen['muestra_leyenda_amazonia'])
        <div class="leyenda-amazonia">
            OPERACIÓN EXONERADA DEL IGV — LEY N° 27037 (AMAZONÍA)
        </div>
    @endif

    @if ($qr)
        <div class="qr-box">
            <img src="{{ $qr }}" alt="QR SUNAT">
            <div class="hash">{{ $venta->hash_cpe }}</div>
        </div>
    @endif

    <div class="center" style="margin-top:6px;">¡Gracias por su compra!</div>

</body>

</html>
