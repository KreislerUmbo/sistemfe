<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        {{-- Rediseño (09-oct-2026): mismo membrete fijo, fuente y estilo de
             secciones que la cotización (alternativa.blade.php). --}}
        @include('pdf.agencia-viajes.partials.membrete-estilos')

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', Arial, Helvetica, sans-serif;
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

        .titulo-doc {
            font-family: 'Poppins-SemiBold', 'Poppins', sans-serif;
            font-size: 17px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 0 0 4px;
        }

        .subtitulo-doc {
            font-size: 11px;
            color: #444444;
            padding-bottom: 12px;
            margin-bottom: 4px;
            border-bottom: 1px solid #dddddd;
        }

        /* ── Contenido (HTML de Quill, ver
           CondicionesGeneralesPdfService::prepararContenido()) ─────── */
        .contenido-html {
            font-size: 11px;
            line-height: 1.3;
            color: #222222;
        }

        .contenido-html p {
            margin: 0 0 5px;
            text-align: justify;
        }

        .contenido-html ul,
        .contenido-html ol {
            margin: 0 0 4px;
            padding-left: 18px;
        }

        .contenido-html li {
            margin-bottom: 1px;
            text-align: justify;
        }

        /* Párrafo en negrita que termina en ":" — presenta la lista de abajo. */
        .contenido-html .cg-subtitulo {
            font-family: 'Poppins-SemiBold', 'Poppins', sans-serif;
            font-weight: bold;
            margin: 8px 0 4px;
            page-break-after: avoid;
        }

        /* Párrafo en negrita largo — declaración destacada (ej. aceptación
           de las condiciones al confirmar la reserva). */
        .contenido-html .cg-destacado {
            font-family: 'Poppins-SemiBold', 'Poppins', sans-serif;
            font-weight: bold;
            margin: 14px 0 6px;
            padding: 8px 12px;
            border-left: 3px solid {{ $configPdf->color_primario ?? '#1f2937' }};
            background: #f5f5f5;
            page-break-inside: avoid;
        }

        /* Subtítulos: párrafos enteros en negrita y encabezados de Quill —
           mismo estilo que .seccion-titulo de la cotización. */
        .contenido-html .cg-seccion-titulo,
        .contenido-html h1,
        .contenido-html h2,
        .contenido-html h3 {
            font-family: 'Poppins-SemiBold', 'Poppins', sans-serif;
            font-weight: bold;
            font-size: 12.5px;
            text-transform: uppercase;
            color: #111111;
            border-bottom: 2px solid {{ $configPdf->color_primario ?? '#1f2937' }};
            padding-bottom: 3px;
            margin: 16px 0 8px;
            page-break-after: avoid;
        }

        .contenido-html .ql-align-justify { text-align: justify; }
        .contenido-html .ql-align-center { text-align: center; }
        .contenido-html .ql-align-right { text-align: right; }
        .contenido-html .ql-indent-1 { margin-left: 18px; }
        .contenido-html .ql-indent-2 { margin-left: 36px; }

        /* ── Datos de pago (igual que la cotización) ─────────────── */
        .seccion {
            margin-top: 16px;
            page-break-inside: avoid;
        }

        .seccion-titulo {
            font-family: 'Poppins-SemiBold', 'Poppins', sans-serif;
            font-weight: bold;
            font-size: 13px;
            text-transform: uppercase;
            border-bottom: 2px solid {{ $configPdf->color_primario ?? '#1f2937' }};
            padding-bottom: 4px;
            margin-bottom: 8px;
        }

        .pagos-table {
            width: 100%;
            margin-top: 6px;
        }

        .pagos-table th {
            font-family: 'Poppins-SemiBold', 'Poppins', sans-serif;
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
    </style>
</head>

<body>
    @include('pdf.agencia-viajes.partials.membrete-header')

    <div class="documento">

        <div class="titulo-doc">{{ $titulo }}</div>
        <div class="subtitulo-doc">
            {{ $empresa->razon_social_comercial ?? $empresa->razon_social ?? '' }}
            @if (!empty($empresa?->n_document)) &nbsp;·&nbsp; RUC {{ $empresa->n_document }} @endif
            &nbsp;·&nbsp; Emitido el {{ $emision }}
        </div>

        <div class="contenido-html">
            {!! $contenidoHtml !!}
        </div>

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

    </div>

    @include('pdf.agencia-viajes.partials.membrete-footer')
</body>

</html>
