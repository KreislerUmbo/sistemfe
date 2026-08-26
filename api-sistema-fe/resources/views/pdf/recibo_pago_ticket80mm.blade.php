<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Recibo de pago N° {{ $receipt->numero_recibo }}</title>
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

        .anulado {
            margin: 4px 0;
            font-size: 9px;
            font-weight: bold;
            text-align: center;
            border: 1px solid #000;
            padding: 3px;
        }

        .leyenda {
            margin-top: 4px;
            font-size: 7.5px;
            text-align: center;
        }
    </style>
</head>

<body>

    @if ($receipt->estado === 'anulado')
        <div class="anulado">
            RECIBO ANULADO<br>
            {{ $receipt->motivo_anulacion ?? '-' }}
        </div>
    @endif

    @if (!empty($logo))
        <div class="center" style="margin-bottom:4px;">
            <img src="{{ $logo }}" style="max-width:150px; max-height:60px;">
        </div>
    @endif

    <div class="center">
        <div class="titulo">{{ $empresa->razon_social_comercial ?? $empresa->razon_social }}</div>
        <div>RUC: {{ $empresa->n_document }}</div>
        <div>{{ $empresa->address }}</div>
    </div>

    <div class="linea"></div>

    <div class="center bold">RECIBO DE PAGO</div>
    <div class="center bold">{{ $receipt->numero_recibo }}</div>

    <div class="linea"></div>

    <div>Fecha: {{ $receipt->fecha_pago->format('d/m/Y') }}</div>
    <div>Cliente: {{ $receipt->client->full_name }}</div>
    <div>{{ $receipt->client->type_document }}: {{ $receipt->client->n_document }}</div>
    <div>Medio: {{ $receipt->medio_pago }}</div>
    @if ($receipt->nro_operacion)
        <div>N° Op.: {{ $receipt->nro_operacion }}</div>
    @endif

    <div class="linea"></div>

    <table class="items">
        @foreach ($receipt->applications as $aplicacion)
            <tr>
                <td class="desc" colspan="2">
                    {{ $aplicacion->sale->n_operacion ?? "#{$aplicacion->sale_id}" }}
                    @if ($aplicacion->installment)
                        (cuota {{ $aplicacion->installment->numero_cuota }})
                    @endif
                    @if ($aplicacion->estado !== 'activo')
                        [{{ $aplicacion->estado }}]
                    @endif
                </td>
            </tr>
            <tr>
                <td>Capital</td>
                <td class="right">S/ {{ number_format($aplicacion->monto_aplicado, 2) }}</td>
            </tr>
            @if ($aplicacion->monto_mora_cobrado > 0)
                <tr>
                    <td>Mora</td>
                    <td class="right">S/ {{ number_format($aplicacion->monto_mora_cobrado, 2) }}</td>
                </tr>
            @endif
        @endforeach
    </table>

    <div class="linea"></div>

    @php $resumen = $receipt->resumenImpresion(); @endphp
    <table class="totales">
        <tr>
            <td>Capital aplicado</td>
            <td class="right">S/ {{ number_format($resumen['total_capital_aplicado'], 2) }}</td>
        </tr>
        @if ($resumen['total_mora_cobrada'] > 0)
            <tr>
                <td>Mora cobrada</td>
                <td class="right">S/ {{ number_format($resumen['total_mora_cobrada'], 2) }}</td>
            </tr>
        @endif
        @if ($receipt->monto_no_aplicado > 0)
            <tr>
                <td>Excedente</td>
                <td class="right">S/ {{ number_format($receipt->monto_no_aplicado, 2) }}</td>
            </tr>
        @endif
        <tr class="bold">
            <td>TOTAL PAGADO</td>
            <td class="right">S/ {{ number_format($receipt->monto_total, 2) }}</td>
        </tr>
    </table>

    <div class="linea"></div>

    <div class="bold">SALDO ACTUAL</div>
    <table class="totales">
        @foreach ($resumen['ventas_afectadas'] as $venta)
            <tr>
                <td>{{ $venta['n_operacion'] ?? "#{$venta['sale_id']}" }}</td>
                <td class="right">S/ {{ number_format($venta['saldo_pendiente_actual'], 2) }}</td>
            </tr>
        @endforeach
    </table>

    <div class="leyenda">
        DOCUMENTO INTERNO DE CONTROL — NO ES COMPROBANTE DE PAGO SUNAT.<br>
        No sustituye la boleta/factura electrónica de la venta original.
    </div>

</body>

</html>
