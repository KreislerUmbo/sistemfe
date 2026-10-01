<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Constancia de cancelación {{ $credito->numero_credito }}</title>
    @include('pdf.creditos._estilos_a4')
</head>
<body>
    @use('App\Services\Creditos\Documentos\FormatoDocumento', 'F')
    @include('pdf.creditos._cabecera_a4', ['titulo' => 'Constancia de cancelación', 'numero' => $credito->numero_credito])

    <p style="font-size: 12px; line-height: 1.8; margin-top: 24px;">
        <strong>{{ $empresa?->razon_social ?? '—' }}</strong>, con RUC {{ $empresa?->n_document ?? '—' }}, deja constancia de que
        <strong>{{ $credito->cliente?->full_name }}</strong>, identificado(a) con {{ $credito->cliente?->type_document }}
        {{ $credito->cliente?->n_document }}, ha cancelado en su totalidad el crédito N.º <strong>{{ $credito->numero_credito }}</strong>,
        otorgado el {{ F::fecha($credito->fecha_desembolso) }} por {{ F::soles((string) $credito->monto_capital) }}
        ({{ F::enLetras((string) $credito->monto_capital) }}).
    </p>
    <p style="font-size: 12px; line-height: 1.8;">
        El último pago se registró el {{ $ultimoPago ? $ultimoPago->fecha_pago->format('d/m/Y') : '—' }}
        @if ($detalle->saldo)
            y el total pagado asciende a {{ F::soles($detalle->saldo->totalPagado) }}
        @endif
        . A la fecha, el cliente <strong>no mantiene deuda pendiente</strong> por este crédito.
    </p>
    <p style="font-size: 12px; line-height: 1.8;">Se expide la presente a solicitud del interesado, el {{ now()->format('d/m/Y') }}.</p>

    <table class="firmas">
        <tr>
            <td></td>
            <td><div class="linea">{{ $empresa?->razon_social ?? '' }}</div></td>
        </tr>
    </table>
</body>
</html>
