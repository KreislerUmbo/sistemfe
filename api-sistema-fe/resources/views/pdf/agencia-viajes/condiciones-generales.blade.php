<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Condiciones Generales del Servicio</title>
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

        .titulo-doc {
            font-size: 17px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 18px 0 14px;
        }

        /* ── Contenido ──────────────────────────────────────────── */
        .contenido-html {
            font-size: 11.5px;
            line-height: 1.6;
        }

        .contenido-html p {
            margin: 0 0 8px;
        }

        .contenido-html h1,
        .contenido-html h2,
        .contenido-html h3 {
            margin: 12px 0 6px;
        }

        /* ── Cuentas bancarias ──────────────────────────────────── */
        .seccion {
            margin-top: 20px;
        }

        .seccion-titulo {
            font-weight: bold;
            font-size: 13px;
            text-transform: uppercase;
            border-bottom: 1px solid #999999;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }

        .pagos-table {
            width: 100%;
            margin-top: 6px;
        }

        .pagos-table th {
            font-size: 10px;
            text-align: left;
            border-bottom: 1px solid #999999;
            padding: 3px 4px;
            background: #f2f2f2;
        }

        .pagos-table td {
            font-size: 11px;
            padding: 4px;
            border-bottom: 1px solid #eeeeee;
        }

        /* ── Footer ─────────────────────────────────────────────── */
        .footer-legal {
            margin-top: 18px;
            border: 1px solid #999999;
            padding: 10px 14px;
            font-size: 11px;
            text-align: center;
            color: #444444;
        }
    </style>
</head>

<body>
    <div class="documento">

        {{-- ══════════════════ HEADER ══════════════════ --}}
        <table style="width:100%;" class="header-wrap">
            <tr>
                <td style="width:170px; vertical-align:top;">
                    @if (!empty($logoUrl))
                        <img src="{{ $logoUrl }}" style="max-width:170px; max-height:70px;">
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

        <div class="titulo-doc">Condiciones Generales del Servicio</div>

        {{-- ══════════════════ CONTENIDO (Quill / HTML) ══════════════════ --}}
        <div class="contenido-html">
            {!! $config?->condiciones_generales_servicio ?? '<p>Sin condiciones configuradas todavía.</p>' !!}
        </div>

        {{-- ══════════════════ CUENTAS BANCARIAS ══════════════════ --}}
        @if ($cuentasBancarias->isNotEmpty())
            <div class="seccion">
                <div class="seccion-titulo">Datos de pago</div>
                <table class="pagos-table">
                    <tr>
                        <th>Banco</th>
                        <th>Titular</th>
                        <th>N° de cuenta</th>
                        <th>CCI</th>
                        <th>Alias</th>
                    </tr>
                    @foreach ($cuentasBancarias as $cuenta)
                        <tr>
                            <td>{{ $cuenta->banco }}</td>
                            <td>{{ $cuenta->titular }}</td>
                            <td>{{ $cuenta->numero_cuenta }}</td>
                            <td>{{ $cuenta->cci ?? '-' }}</td>
                            <td>{{ $cuenta->alias ?? '-' }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif

        {{-- ══════════════════ FOOTER ══════════════════ --}}
        <div class="footer-legal">
            Documento generado el {{ now()->format('d/m/Y') }}.
        </div>

    </div>
</body>

</html>
