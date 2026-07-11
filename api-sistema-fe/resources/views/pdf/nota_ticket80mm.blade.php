<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>{{ $nota->tipo_doc === '07' ? 'Nota de Crédito' : 'Nota de Débito' }} N° {{ $nota->id }}</title>
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

        .ref-doc {
            font-size: 8px;
            margin-top: 4px;
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
        {{ $nota->tipo_doc === '07' ? 'NOTA DE CRÉDITO ELECTRÓNICA' : 'NOTA DE DÉBITO ELECTRÓNICA' }}
    </div>
    <div class="center bold">
        {{ $nota->n_operacion ?? ($nota->serie . '-PENDIENTE') }}
    </div>

    <div class="linea"></div>

    <div>Fecha: {{ optional($nota->sunat_sent_at)->format('d/m/Y H:i') ?? '-' }}</div>
    <div>Cliente: {{ $nota->client->full_name }}</div>
    <div>{{ $nota->client->type_document }}: {{ $nota->client->n_document }}</div>
    <div>Vendedor: {{ $nota->user->name }} {{ $nota->user->surname }}</div>

    <div class="ref-doc">
        Modifica: {{ $nota->serie_afectada }}-{{ str_pad((string) $nota->correlativo_afectado, 8, '0', STR_PAD_LEFT) }}<br>
        Motivo ({{ $nota->cod_motivo }}): {{ $nota->des_motivo }}
    </div>

    <div class="linea"></div>

    <table class="items">
        @foreach ($nota->note_details as $detalle)
            <tr>
                <td class="desc" colspan="2">{{ $detalle->description ?? $detalle->product->title }}</td>
            </tr>
            <tr>
                <td>
                    {{ rtrim(rtrim(number_format($detalle->quantity, 2), '0'), '.') }}
                    {{ $detalle->unidad_medida }}
                    x {{ $nota->currency }} {{ number_format($detalle->price_final, 2) }}
                </td>
                <td class="right">{{ $nota->currency }} {{ number_format($detalle->subtotal, 2) }}</td>
            </tr>
        @endforeach
    </table>

    <div class="linea"></div>

    @php $resumen = $nota->resumenImpresion(); @endphp

    <table class="totales">
        <tr>
            <td>Subtotal</td>
            <td class="right">{{ $nota->currency }} {{ number_format($resumen['sub_total_nota'], 2) }}</td>
        </tr>
        <tr>
            <td>IGV</td>
            <td class="right">{{ $nota->currency }} {{ number_format($resumen['igv_total'], 2) }}</td>
        </tr>
        @if ($resumen['icbper_total'] > 0)
            <tr>
                <td>ICBPER</td>
                <td class="right">{{ $nota->currency }} {{ number_format($resumen['icbper_total'], 2) }}</td>
            </tr>
        @endif
    </table>

    <div class="linea"></div>

    <table class="totales">
        <tr class="bold">
            <td>TOTAL</td>
            <td class="right">{{ $nota->currency }} {{ number_format($resumen['total_nota'], 2) }}</td>
        </tr>
    </table>

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
            <div class="hash">{{ $nota->hash_cpe }}</div>
        </div>
    @endif

    <div class="center" style="margin-top:6px;">Documento sin valor si no está firmado electrónicamente.</div>

</body>

</html>
