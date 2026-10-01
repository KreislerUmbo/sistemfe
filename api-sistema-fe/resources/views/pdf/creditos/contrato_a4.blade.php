<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato {{ $credito?->numero_credito }}</title>
    @include('pdf.creditos._estilos_a4')
    <style>
        .contrato { font-size: 11px; line-height: 1.55; text-align: justify; }
        .contrato h1, .contrato h2 { font-size: 14px; text-align: center; margin: 10px 0; }
        .contrato h3, .contrato h4 { font-size: 11.5px; margin: 12px 0 4px; }
        .contrato p { margin: 0 0 8px; }
        .contrato .ql-align-center { text-align: center; }
        .contrato .ql-align-right { text-align: right; }
        .contrato .ql-align-justify { text-align: justify; }
        .contrato table { width: 100%; border-collapse: collapse; margin: 6px 0 10px; }
        .contrato th { background: #e6e6e6; border: 1px solid #999999; font-size: 10px; padding: 4px; text-align: left; }
        .contrato td { border: 1px solid #dddddd; font-size: 10px; padding: 4px; }
        .contrato .derecha { text-align: right; }
    </style>
</head>
<body>
    {{-- El cuerpo es la plantilla sanitizada (PlantillaContratoService) con valores ya escapados. --}}
    <div class="contrato">{!! $cuerpo !!}</div>

    <table class="firmas">
        <tr>
            <td><div class="linea">EL PRESTATARIO<br>{{ $credito?->cliente?->full_name ?? '' }}<br>{{ $credito?->cliente?->type_document }} {{ $credito?->cliente?->n_document }}</div></td>
            <td><div class="linea">EL PRESTAMISTA<br>{{ $empresa?->razon_social ?? '' }}<br>RUC {{ $empresa?->n_document ?? '' }}</div></td>
        </tr>
    </table>
</body>
</html>
