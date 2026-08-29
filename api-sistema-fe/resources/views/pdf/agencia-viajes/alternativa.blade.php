<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Cotización {{ $cotizacion->codigo }} - {{ $alternativa->nombre }}</title>
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
            margin: 18px 0 4px;
        }

        .subtitulo-doc {
            font-size: 13px;
            color: #444444;
            margin-bottom: 14px;
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
            text-transform: uppercase;
        }

        .info-grid td {
            font-size: 12px;
            padding: 1px 0;
            vertical-align: top;
        }

        /* ── Secciones de contenido ─────────────────────────────── */
        .seccion {
            margin-top: 16px;
        }

        .seccion-titulo {
            font-weight: bold;
            font-size: 13px;
            text-transform: uppercase;
            border-bottom: 1px solid #999999;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }

        .seccion-html {
            font-size: 11px;
            line-height: 1.5;
        }

        .seccion-html p {
            margin: 0 0 6px;
        }

        .dia-item {
            margin-bottom: 8px;
        }

        .dia-item .dia-label {
            font-weight: bold;
            font-size: 12px;
        }

        .dia-item .paso {
            font-size: 11px;
            margin-left: 12px;
        }

        ul.lista-simple {
            margin: 0;
            padding-left: 18px;
        }

        ul.lista-simple li {
            font-size: 11px;
            margin-bottom: 3px;
        }

        /* ── Tabla de precio ────────────────────────────────────── */
        .items {
            width: 100%;
            margin-top: 8px;
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

        .precio-tachado {
            text-decoration: line-through;
            color: #999999;
            margin-right: 6px;
        }

        .descuento-nota {
            font-size: 10px;
            color: #b45309;
            margin-left: 4px;
        }

        /* ── Totales ────────────────────────────────────────────── */
        .totales {
            width: 280px;
            margin-left: auto;
            margin-top: 10px;
            font-size: 12px;
        }

        .totales td {
            padding: 2px 0;
        }

        .totales .valor {
            text-align: right;
            width: 110px;
        }

        .totales .total-final td {
            font-weight: bold;
            font-size: 14px;
            border-top: 1px solid #111111;
            padding-top: 5px;
        }

        /* ── Cuentas bancarias ──────────────────────────────────── */
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
            line-height: 1.6;
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

        <div class="titulo-doc">Cotización {{ $cotizacion->codigo }}</div>
        <div class="subtitulo-doc">{{ $alternativa->nombre }}</div>

        {{-- ══════════════════ CLIENTE / FECHAS ══════════════════ --}}
        @php
            $conteoPax = $pasajeros->groupBy('tipo_pax')->map->count();
            $etiquetasPax = ['adulto' => 'Adulto(s)', 'nino' => 'Niño(s)', 'infante' => 'Infante(s)'];
        @endphp
        <table style="width:100%; margin-top:14px;">
            <tr>
                <td class="info-box" style="width:60%;">
                    <div class="titulo">Datos del cliente</div>
                    <table class="info-grid">
                        <tr>
                            <td style="width:90px;">Cliente</td>
                            <td>: {{ $cliente->full_name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td>Destino</td>
                            <td>: {{ $cotizacion->destino ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td>Fechas</td>
                            <td>:
                                {{ $cotizacion->fecha_viaje_desde ? \Carbon\Carbon::parse($cotizacion->fecha_viaje_desde)->format('d/m/Y') : 'por confirmar' }}
                                &nbsp;—&nbsp;
                                {{ $cotizacion->fecha_viaje_hasta ? \Carbon\Carbon::parse($cotizacion->fecha_viaje_hasta)->format('d/m/Y') : 'por confirmar' }}
                            </td>
                        </tr>
                        <tr>
                            <td>Pasajeros</td>
                            <td>:
                                @if ($conteoPax->isEmpty())
                                    -
                                @else
                                    @foreach ($conteoPax as $tipo => $cantidad)
                                        {{ $cantidad }} {{ $etiquetasPax[$tipo] ?? $tipo }}{{ !$loop->last ? ', ' : '' }}
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="width:12px;"></td>
                <td class="info-box" style="width:40%;">
                    <table class="info-grid">
                        <tr>
                            <td style="width:110px;">Fecha emisión</td>
                            <td>: {{ now()->format('d/m/Y') }}</td>
                        </tr>
                        @if ($alternativa->fecha_vencimiento)
                            <tr>
                                <td>Vigencia</td>
                                <td>: hasta {{ \Carbon\Carbon::parse($alternativa->fecha_vencimiento)->format('d/m/Y') }}</td>
                            </tr>
                        @elseif ($config?->dias_vigencia_cotizacion)
                            <tr>
                                <td>Vigencia</td>
                                <td>: {{ $config->dias_vigencia_cotizacion }} días desde la emisión</td>
                            </tr>
                        @endif
                        <tr>
                            <td>Moneda</td>
                            <td>: {{ $alternativa->moneda_cotizacion }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- ══════════════════ ITINERARIO ══════════════════ --}}
        @if (count($itinerario) > 0)
            <div class="seccion">
                <div class="seccion-titulo">Itinerario</div>
                @foreach (collect($itinerario)->groupBy('dia') as $dia => $pasosDelDia)
                    <div class="dia-item">
                        <div class="dia-label">Día {{ $dia }}</div>
                        @foreach ($pasosDelDia as $paso)
                            <div class="paso">
                                @if ($paso['hora'])
                                    <strong>{{ substr($paso['hora'], 0, 5) }}</strong> —
                                @endif
                                {{-- Rich text (Quill) desde 2026-08-28 — antes era texto plano,
                                     por eso acá se renderiza crudo en vez de escaparse. --}}
                                {!! $paso['descripcion'] !!}
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ══════════════════ INCLUYE ══════════════════ --}}
        <div class="seccion">
            <div class="seccion-titulo">Incluye</div>
            <ul class="lista-simple">
                @foreach ($items as $item)
                    <li>{{ $item['nombre'] }}</li>
                @endforeach
            </ul>
        </div>

        {{-- ══════════════════ NO INCLUYE / RECOMENDACIONES / LUGAR DE RECOJO / HORARIOS ══════════════════ --}}
        @if ($tourUnico)
            @if (!empty($tourUnico->no_incluye))
                <div class="seccion">
                    <div class="seccion-titulo">No incluye</div>
                    <div class="seccion-html">{!! $tourUnico->no_incluye !!}</div>
                </div>
            @endif
            @if (!empty($tourUnico->recomendaciones))
                <div class="seccion">
                    <div class="seccion-titulo">Recomendaciones</div>
                    <div class="seccion-html">{!! $tourUnico->recomendaciones !!}</div>
                </div>
            @endif
            @if (!empty($tourUnico->lugar_recojo) || !empty($tourUnico->hora_salida) || !empty($tourUnico->hora_retorno))
                <div class="seccion">
                    <div class="seccion-titulo">Lugar de recojo y horarios</div>
                    <table class="info-grid">
                        @if (!empty($tourUnico->lugar_recojo))
                            <tr>
                                <td style="width:110px;">Lugar de recojo</td>
                                <td>: {{ $tourUnico->lugar_recojo }}</td>
                            </tr>
                        @endif
                        @if (!empty($tourUnico->hora_salida))
                            <tr>
                                <td>Hora de salida</td>
                                <td>: {{ $tourUnico->hora_salida }}</td>
                            </tr>
                        @endif
                        @if (!empty($tourUnico->hora_retorno))
                            <tr>
                                <td>Hora de retorno</td>
                                <td>: {{ $tourUnico->hora_retorno }}</td>
                            </tr>
                        @endif
                    </table>
                </div>
            @endif
        @endif

        {{-- ══════════════════ TABLA DE PRECIO ══════════════════ --}}
        <div class="seccion">
            <div class="seccion-titulo">Precio</div>
            <table class="items">
                <colgroup>
                    <col>
                    <col style="width:150px;">
                </colgroup>
                <thead>
                    <tr>
                        <th>Descripción</th>
                        <th class="right">Precio</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>{{ $item['nombre'] }}</td>
                            <td class="right">
                                @if ($config?->formato_descuento_pdf === 'tachado' && $hayDescuento && $item['descuento_pct'] > 0)
                                    <span class="precio-tachado">{{ $alternativa->moneda_cotizacion }} {{ number_format($item['precio_original'], 2) }}</span>
                                @endif
                                {{ $alternativa->moneda_cotizacion }} {{ number_format($item['precio'], 2) }}
                                @if ($config?->formato_descuento_pdf === 'separado' && $hayDescuento && $item['descuento_pct'] > 0)
                                    <span class="descuento-nota">(-{{ rtrim(rtrim(number_format($item['descuento_pct'], 2), '0'), '.') }}%)</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table class="totales">
                @if ($config?->mostrar_descuento_como_linea && $hayDescuento)
                    <tr>
                        <td>Descuento aplicado</td>
                        <td class="valor">- {{ $alternativa->moneda_cotizacion }} {{ number_format($descuentoMonto, 2) }}</td>
                    </tr>
                @endif
                <tr class="total-final">
                    <td>Total</td>
                    <td class="valor">{{ $alternativa->moneda_cotizacion }} {{ number_format($total, 2) }}</td>
                </tr>
            </table>
        </div>

        {{-- ══════════════════ DATOS DE PAGO ══════════════════ --}}
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
            Consultá las condiciones generales del servicio en el documento adjunto.
        </div>

    </div>
</body>

</html>
