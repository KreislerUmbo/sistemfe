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

        .dia-item .paso .paso-atractivo {
            font-weight: bold;
        }

        /* ── Fotos de tour en el itinerario (Simulación Panamá, 04-sep-2026) ── */
        .itinerario-fotos {
            margin: 4px 0 6px;
        }

        .itinerario-fotos img {
            max-width: 140px;
            max-height: 100px;
            margin-right: 6px;
            border: 1px solid #cccccc;
        }

        ul.lista-simple {
            margin: 0;
            padding-left: 18px;
        }

        ul.lista-simple li {
            font-size: 11px;
            margin-bottom: 3px;
        }

        /* ── Bloques de destino (itinerario/incluye, 12f-3) ───────── */
        .destino-bloque-titulo {
            font-weight: bold;
            font-size: 12px;
            margin: 10px 0 4px;
        }

        .destino-bloque-titulo:first-child {
            margin-top: 0;
        }

        .destino-bloque-fechas {
            font-weight: normal;
            font-size: 11px;
            color: #444444;
        }

        /* ── Opciones de hoteles (Sesión M5, matriz hotel × habitación) ──── */
        .hoteles-tabla {
            width: 100%;
            margin-bottom: 12px;
        }

        .hoteles-tabla th {
            font-size: 10px;
            text-align: left;
            border: 1px solid #999999;
            padding: 4px 6px;
            background: #f2f2f2;
            text-transform: uppercase;
        }

        .hoteles-tabla th.precio-col {
            text-align: right;
        }

        .hoteles-tabla td {
            font-size: 11px;
            border: 1px solid #999999;
            padding: 4px 6px;
        }

        .hoteles-tabla td.precio-col {
            text-align: right;
        }

        .hoteles-tabla td.sin-precio {
            text-align: center;
            color: #999999;
        }

        .hoteles-tabla tr.fila-elegida td {
            font-weight: bold;
            background: #f7f7f7;
        }

        .hoteles-elegida-nota {
            font-size: 10px;
            color: #444444;
            font-weight: normal;
            text-transform: none;
        }

        /* ── Tours opcionales (Simulación Panamá, 04-sep-2026) ─────── */
        .opcional-item {
            border: 1px solid #cccccc;
            border-radius: 3px;
            padding: 6px 8px;
            margin-bottom: 6px;
        }

        .opcional-nombre {
            font-weight: bold;
            font-size: 12px;
        }

        .opcional-precio {
            font-weight: normal;
            float: right;
        }

        .opcional-detalle {
            font-size: 11px;
            color: #333333;
            margin-top: 3px;
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

        {{-- ══════════════════ VUELO (Simulación Panamá, 04-sep-2026) ══════════════════ --}}
        {{-- Datos de vuelo de la opción de mayorista elegida
             (opcion_mayorista.vuelo_aerolinea/vuelo_detalle) — capturados
             en el drawer del cotizador desde Sesión 7b pero nunca
             impresos hasta ahora; el PDF solo se había probado contra
             paquetes Local/Nacional (sin vuelo propio). --}}
        @if (count($mayoristasVuelo) > 0)
            <div class="seccion">
                <div class="seccion-titulo">Vuelo</div>
                @foreach ($mayoristasVuelo as $vuelo)
                    <div class="seccion-html">
                        <strong>{{ $vuelo['aerolinea'] }}</strong>
                        @if (!empty($vuelo['detalle']))
                            <br>{!! nl2br(e($vuelo['detalle'])) !!}
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ══════════════════ ITINERARIO ══════════════════ --}}
        {{-- 12f-3 — $itinerario es un array de BLOQUES (uno por destino con
             al menos 1 paso). Con 1 solo bloque no se imprime encabezado de
             destino, mismo look que antes de multi-destino. --}}
        @if (count($itinerario) > 0)
            <div class="seccion">
                <div class="seccion-titulo">Itinerario</div>
                @foreach ($itinerario as $bloque)
                    @if (count($itinerario) > 1)
                        <div class="destino-bloque-titulo">
                            {{ $bloque['destino_nombre'] }}
                            @if ($bloque['fecha_inicio'] || $bloque['fecha_fin'])
                                <span class="destino-bloque-fechas">
                                    ({{ $bloque['fecha_inicio']?->format('d/m/Y') ?? '?' }}
                                    —
                                    {{ $bloque['fecha_fin']?->format('d/m/Y') ?? '?' }})
                                </span>
                            @endif
                        </div>
                    @endif
                    @foreach (collect($bloque['pasos'])->groupBy('dia') as $dia => $pasosDelDia)
                        <div class="dia-item">
                            {{-- Feedback del usuario sobre el PDF real: faltaba el
                                 nombre del tour como título del día — mismo patrón
                                 que los 3 documentos reales que originaron la
                                 sección de hoteles ("DÍA 02: FULL DAY ALTO MAYO"),
                                 antes solo decía "Día 2". Un mismo bloque de "día"
                                 pertenece siempre a un único tour (offset secuencial
                                 de itinerarioAlternativa()), así que el nombre del
                                 primer paso alcanza para todo el grupo. --}}
                            <div class="dia-label">
                                Día {{ $dia }}
                                @if ($pasosDelDia->first()['tour_nombre'] ?? null)
                                    : {{ $pasosDelDia->first()['tour_nombre'] }}
                                @endif
                            </div>
                            {{-- Simulación Panamá (04-sep-2026) — fotos del tour
                                 (PaquetePlantilla.fotos), una vez por día igual que
                                 tour_nombre arriba, no una vez por paso. --}}
                            @if (!empty($pasosDelDia->first()['tour_fotos'] ?? null))
                                <div class="itinerario-fotos">
                                    @foreach ($pasosDelDia->first()['tour_fotos'] as $foto)
                                        <img src="{{ $foto }}">
                                    @endforeach
                                </div>
                            @endif
                            @foreach ($pasosDelDia as $paso)
                                <div class="paso">
                                    @if ($paso['hora'])
                                        <strong>{{ substr($paso['hora'], 0, 5) }}</strong>
                                    @endif
                                    {{-- Feedback del usuario: el nombre del atractivo
                                         ('destino_atractivo_id', ej. "Tio Yacu", "Baños
                                         Termales") es un dato estructurado aparte de la
                                         descripción en texto libre — confirmado contra
                                         datos reales de agencia-demo, casi nunca se
                                         repite tal cual dentro de la prosa. Sin esto el
                                         PDF nunca lo mostraba pese a que el vendedor ya
                                         lo carga al armar el tour en el catálogo. --}}
                                    @if ($paso['atractivo_nombre'] ?? null)
                                        <span class="paso-atractivo">{{ $paso['atractivo_nombre'] }}</span>
                                    @endif
                                    @if ($paso['hora'] || ($paso['atractivo_nombre'] ?? null))
                                        —
                                    @endif
                                    {{-- Rich text (Quill) desde 2026-08-28 — antes era texto plano,
                                         por eso acá se renderiza crudo en vez de escaparse. --}}
                                    {!! $paso['descripcion'] !!}
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                @endforeach
            </div>
        @endif

        {{-- ══════════════════ INCLUYE ══════════════════ --}}
        {{-- 12f-3 — $incluyePorDestino es un array de bloques (uno por
             destino con al menos 1 ítem), mismo criterio que Itinerario. --}}
        @if (count($incluyePorDestino) > 0 || count($mayoristasIncluye) > 0)
            <div class="seccion">
                <div class="seccion-titulo">Incluye</div>
                @foreach ($incluyePorDestino as $bloque)
                    @if (count($incluyePorDestino) > 1)
                        <div class="destino-bloque-titulo">
                            {{ $bloque['destino_nombre'] }}
                            @if ($bloque['fecha_inicio'] || $bloque['fecha_fin'])
                                <span class="destino-bloque-fechas">
                                    ({{ $bloque['fecha_inicio']?->format('d/m/Y') ?? '?' }}
                                    —
                                    {{ $bloque['fecha_fin']?->format('d/m/Y') ?? '?' }})
                                </span>
                            @endif
                        </div>
                    @endif
                    <ul class="lista-simple">
                        @foreach ($bloque['nombres'] as $nombre)
                            <li>{{ $nombre }}</li>
                        @endforeach
                    </ul>
                @endforeach
                {{-- Simulación Panamá (04-sep-2026) — "Paquete Incluye" de
                     la opción de mayorista elegida (opcion_mayorista.incluye,
                     texto libre explotado por línea), nunca antes impreso. --}}
                @if (count($mayoristasIncluye) > 0)
                    <ul class="lista-simple">
                        @foreach ($mayoristasIncluye as $linea)
                            <li>{{ $linea }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        {{-- ══════════════════ NO INCLUYE / RECOMENDACIONES / LUGAR DE RECOJO / HORARIOS ══════════════════ --}}
        {{-- Simulación Panamá (04-sep-2026) — antes solo disparaba con
             $tourUnico (paquete Local/Nacional basado en un único
             PaquetePlantilla); un paquete de mayorista nunca tiene
             tour_origen_id, así que esta sección nunca corría pese a que
             el vendedor sí puede cargar "No incluye" del paquete base
             (opcion_mayorista.no_incluye, campo nuevo). --}}
        @if (!empty($tourUnico?->no_incluye) || count($mayoristasNoIncluye) > 0)
            <div class="seccion">
                <div class="seccion-titulo">No incluye</div>
                @if (!empty($tourUnico?->no_incluye))
                    <div class="seccion-html">{!! $tourUnico->no_incluye !!}</div>
                @endif
                @if (count($mayoristasNoIncluye) > 0)
                    <ul class="lista-simple">
                        @foreach ($mayoristasNoIncluye as $linea)
                            <li>{{ $linea }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
        @if ($tourUnico)
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

        {{-- ══════════════════ OPCIONES DE HOTELES (Sesión M5) ══════════════════ --}}
        {{-- Sección propia, después de itinerario/incluye — mismo formato que
             los 3 documentos reales que originaron el plan (docs/auxiliares/):
             tabla matriz hotel × tipo de habitación, un bloque por grupo
             (plan-matriz-hoteles-cotizador.md P10). Un grupo TODAVÍA abierto
             (nadie eligió) se muestra igual que en esos documentos — es
             justamente el estado en que se les envía al cliente para que
             decida. Un grupo ya resuelto resalta la fila elegida (mejora
             sobre el formato original, esos documentos nunca se reenviaban
             después de la decisión). --}}
        @foreach ($opcionesHoteles as $grupoHotel)
            <div class="seccion">
                <div class="seccion-titulo">
                    Opciones de hoteles
                    @if ($grupoHotel['resuelto'])
                        <span class="hoteles-elegida-nota">— opción confirmada resaltada</span>
                    @endif
                </div>
                <table class="hoteles-tabla">
                    <tr>
                        <th>Hotel</th>
                        @foreach ($grupoHotel['tipos_habitacion'] as $tipo)
                            <th class="precio-col">{{ ucfirst($tipo) }}</th>
                        @endforeach
                    </tr>
                    @foreach ($grupoHotel['filas'] as $fila)
                        <tr class="{{ $fila['elegida'] ? 'fila-elegida' : '' }}">
                            {{-- '✓' (U+2713) no renderiza con la fuente que usa DomPDF acá
                                 — sale como "?" (confirmado generando el PDF real contra
                                 agencia-demo). Texto plano en vez de un glifo unicode. --}}
                            <td>{{ $fila['hotel'] }}{{ $fila['elegida'] ? ' (elegida)' : '' }}</td>
                            @foreach ($grupoHotel['tipos_habitacion'] as $tipo)
                                @if (isset($fila['precios'][$tipo]))
                                    <td class="precio-col">{{ $alternativa->moneda_cotizacion }} {{ number_format($fila['precios'][$tipo], 2) }}</td>
                                @else
                                    <td class="sin-precio">—</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach

        {{-- ══════════════════ TOURS OPCIONALES (Simulación Panamá, 04-sep-2026) ══════════════════ --}}
        {{-- OpcionMayoristaOpcional (nombre/precio_por_persona/incluye/
             no_incluye) — el modelo y el panel del drawer ya existían
             (sesión 29-ago-2026), pero el PDF nunca los listaba. Nunca se
             suman al total (mismo criterio del drawer: "actividades que el
             cliente puede agregar aparte"). --}}
        @if (count($mayoristasOpcionales) > 0)
            <div class="seccion">
                <div class="seccion-titulo">Tours opcionales</div>
                @foreach ($mayoristasOpcionales as $opcional)
                    <div class="opcional-item">
                        <div class="opcional-nombre">
                            {{ $opcional->nombre }}
                            <span class="opcional-precio">{{ $opcional->moneda }} {{ number_format($opcional->precio_por_persona, 2) }} /pax</span>
                        </div>
                        @if (!empty($opcional->incluye))
                            <div class="opcional-detalle"><strong>Incluye:</strong> {{ $opcional->incluye }}</div>
                        @endif
                        @if (!empty($opcional->no_incluye))
                            <div class="opcional-detalle"><strong>No incluye:</strong> {{ $opcional->no_incluye }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ══════════════════ PRECIO ══════════════════ --}}
        {{-- 12f-3 — deja de mostrarse precio por ítem individual (decisión
             del usuario, brief 12f3 §0.3); queda solo el bloque de
             totales. `formato_descuento_pdf` ya no se lee acá — era una
             configuración pensada para decorar el precio por fila, que ya
             no existe en esta vista. --}}
        <div class="seccion">
            <div class="seccion-titulo">Precio</div>
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
