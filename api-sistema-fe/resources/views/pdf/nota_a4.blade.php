<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>{{ $nota->tipo_doc === '07' ? 'Nota de Crédito' : 'Nota de Débito' }} N° {{ $nota->id }}</title>
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
            border-bottom: 2px solid #111111;
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

        .doc-box .ruc {
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 0.5px;
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

        /* ── Cliente / fechas ───────────────────────────────────── */
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

        /* ── Tabla de ítems ─────────────────────────────────────── */
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

        /* ── Footer ─────────────────────────────────────────────── */
        .footer-wrap {
            width: 100%;
            margin-top: 16px;
        }

        .footer-legal {
            border: 1px solid #999999;
            padding: 10px 14px;
            font-size: 11px;
            line-height: 1.6;
            vertical-align: top;
        }

        .footer-legal .hash {
            font-family: 'Courier New', monospace;
        }

        .qr-box {
            width: 130px;
            text-align: center;
            vertical-align: top;
        }

        .qr-box img {
            width: 120px;
            height: 120px;
        }
    </style>
</head>

<body>
    <div class="documento">

        {{-- ══════════════════ HEADER ══════════════════ --}}
        <table class="header-wrap" style="width:100%;">
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
                                <div class="ruc">RUC {{ $empresa->n_document }}</div>
                                <div class="tipo">
                                    {{ $nota->tipo_doc === '07' ? 'NOTA DE CRÉDITO ELECTRÓNICA' : 'NOTA DE DÉBITO ELECTRÓNICA' }}
                                </div>
                                <div class="numero">
                                    {{ $nota->n_operacion ?? ($nota->serie . '-PENDIENTE') }}
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- ══════════════════ CLIENTE / FECHAS ══════════════════ --}}
        @php
            $moneda_label = $nota->currency === 'USD' ? 'DÓLARES' : 'SOLES';
        @endphp
        <table style="width:100%; margin-top:16px;">
            <tr>
                <td class="info-box" style="width:60%;">
                    <div class="titulo">DATOS DEL CLIENTE</div>
                    <table class="info-grid">
                        <tr>
                            <td style="width:110px;">{{ $nota->client->type_document }}</td>
                            <td>: {{ $nota->client->n_document }}</td>
                        </tr>
                        <tr>
                            <td>DENOMINACIÓN</td>
                            <td>: {{ $nota->client->full_name }}</td>
                        </tr>
                        <tr>
                            <td>DIRECCIÓN</td>
                            <td>: {{ $nota->client->address ?: '-' }}</td>
                        </tr>
                    </table>
                </td>
                <td style="width:12px;"></td>
                <td class="info-box" style="width:40%;">
                    <table class="info-grid">
                        <tr>
                            <td style="width:135px;">FECHA EMISIÓN</td>
                            <td>: {{ optional($nota->sunat_sent_at)->format('d/m/Y') ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td>MONEDA</td>
                            <td>: {{ $moneda_label }}</td>
                        </tr>
                        <tr>
                            <td>VENDEDOR</td>
                            <td>: {{ $nota->user->name }} {{ $nota->user->surname }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- ══════════════════ DOCUMENTO QUE MODIFICA / MOTIVO ══════════════════ --}}
        <div class="caja-redonda compacta" style="text-align:left;">
            <div><span style="font-weight:bold;">Documento que modifica:</span>
                {{ $nota->serie_afectada }}-{{ str_pad((string) $nota->correlativo_afectado, 8, '0', STR_PAD_LEFT) }}
            </div>
            <div><span style="font-weight:bold;">Motivo ({{ $nota->cod_motivo }}):</span> {{ $nota->des_motivo }}</div>
        </div>

        {{-- ══════════════════ TABLA DE ÍTEMS ══════════════════ --}}
        <table class="items">
            <colgroup>
                <col style="width:40px;">
                <col style="width:45px;">
                <col style="width:55px;">
                <col>
                <col style="width:80px;">
                <col style="width:80px;">
                <col style="width:90px;">
            </colgroup>
            <thead>
                <tr>
                    <th>CANT.</th>
                    <th>UM</th>
                    <th>CÓD.</th>
                    <th>DESCRIPCIÓN</th>
                    <th class="right">V.U</th>
                    <th class="right">P.U</th>
                    <th class="right">IMPORTE</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($nota->note_details as $detalle)
                    <tr>
                        <td>{{ rtrim(rtrim(number_format($detalle->quantity, 2), '0'), '.') }}</td>
                        <td>{{ $detalle->unidad_medida }}</td>
                        <td>{{ $detalle->product->sku ?? '-' }}</td>
                        <td>{{ $detalle->description ?? $detalle->product->title }}</td>
                        <td class="right">{{ number_format($detalle->price_base, 3) }}</td>
                        <td class="right">{{ number_format($detalle->price_final, 3) }}</td>
                        <td class="right">{{ number_format($detalle->subtotal, 2) }}</td>
                    </tr>
                @endforeach
                {{-- Filas vacías de relleno visual, para que la tabla no se vea cortada con pocos ítems --}}
                @for ($i = $nota->note_details->count(); $i < 4; $i++)
                    <tr>
                        <td>&nbsp;</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                @endfor
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7" style="height:1px; padding:0;"></td>
                </tr>
            </tfoot>
        </table>

        {{-- ══════════════════ TOTALES ══════════════════ --}}
        @php $resumen = $nota->resumenImpresion(); @endphp
        <table class="totales">
            @if ($nota->mto_oper_gravadas > 0)
                <tr>
                    <td>OP. GRAVADAS</td>
                    <td class="moneda">{{ $nota->currency === 'USD' ? 'US$' : 'S/' }}</td>
                    <td class="valor">{{ number_format($nota->mto_oper_gravadas, 2) }}</td>
                </tr>
            @endif
            @if ($nota->mto_oper_exoneradas > 0)
                <tr>
                    <td>OP. EXONERADAS</td>
                    <td class="moneda">{{ $nota->currency === 'USD' ? 'US$' : 'S/' }}</td>
                    <td class="valor">{{ number_format($nota->mto_oper_exoneradas, 2) }}</td>
                </tr>
            @endif
            @if ($nota->mto_oper_inafectas > 0)
                <tr>
                    <td>OP. INAFECTAS</td>
                    <td class="moneda">{{ $nota->currency === 'USD' ? 'US$' : 'S/' }}</td>
                    <td class="valor">{{ number_format($nota->mto_oper_inafectas, 2) }}</td>
                </tr>
            @endif
            @if ($nota->mto_oper_exportacion > 0)
                <tr>
                    <td>OP. EXPORTACIÓN</td>
                    <td class="moneda">{{ $nota->currency === 'USD' ? 'US$' : 'S/' }}</td>
                    <td class="valor">{{ number_format($nota->mto_oper_exportacion, 2) }}</td>
                </tr>
            @endif
            <tr>
                <td>IGV</td>
                <td class="moneda">{{ $nota->currency === 'USD' ? 'US$' : 'S/' }}</td>
                <td class="valor">{{ number_format($resumen['igv_total'], 2) }}</td>
            </tr>
            @if ($resumen['icbper_total'] > 0)
                <tr>
                    <td>ICBPER</td>
                    <td class="moneda">{{ $nota->currency === 'USD' ? 'US$' : 'S/' }}</td>
                    <td class="valor">{{ number_format($resumen['icbper_total'], 2) }}</td>
                </tr>
            @endif
            @if ($resumen['isc_total'] > 0)
                <tr>
                    <td>ISC</td>
                    <td class="moneda">{{ $nota->currency === 'USD' ? 'US$' : 'S/' }}</td>
                    <td class="valor">{{ number_format($resumen['isc_total'], 2) }}</td>
                </tr>
            @endif
            <tr class="total-final">
                <td>TOTAL</td>
                <td class="moneda">{{ $nota->currency === 'USD' ? 'US$' : 'S/' }}</td>
                <td class="valor">{{ number_format($resumen['total_nota'], 2) }}</td>
            </tr>
        </table>

        {{-- ══════════════════ IMPORTE EN LETRAS ══════════════════ --}}
        <div class="caja-redonda">
            <span style="font-weight:bold;">IMPORTE EN LETRAS:</span> {{ $resumen['monto_en_letras'] }}
        </div>

        {{-- ══════════════════ LEYENDA AMAZONÍA (si aplica) ══════════════════ --}}
        @if ($resumen['muestra_leyenda_amazonia'])
            <div class="caja-redonda compacta" style="font-weight:bold;">
                OPERACIÓN EXONERADA DEL IGV — LEY DE PROMOCIÓN DE LA INVERSIÓN EN LA AMAZONÍA (LEY N° 27037)
            </div>
        @endif

        {{-- ══════════════════ FOOTER ══════════════════ --}}
        <table class="footer-wrap">
            <tr>
                <td class="footer-legal">
                    <div>Representación impresa de
                        la {{ $nota->tipo_doc === '07' ? 'NOTA DE CRÉDITO ELECTRÓNICA' : 'NOTA DE DÉBITO ELECTRÓNICA' }}.
                    </div>
                    <div>Autorizada mediante Resolución de Superintendencia N° 097-2012/SUNAT y modificatorias.</div>
                    @if ($nota->hash_cpe)
                        <div>Resumen: <span class="hash">{{ $nota->hash_cpe }}</span></div>
                    @else
                        <div>Documento aún no enviado a SUNAT — no tiene valor tributario hasta su emisión.</div>
                    @endif
                </td>
                @if ($qr)
                    <td class="qr-box">
                        <img src="{{ $qr }}" alt="QR SUNAT">
                    </td>
                @endif
            </tr>
        </table>

    </div>
</body>

</html>
