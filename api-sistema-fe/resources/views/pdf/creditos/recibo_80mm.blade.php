<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo {{ $pago->numero_recibo }}</title>
    @include('pdf.creditos._estilos_80mm')
</head>
<body>
    @use('App\Services\Creditos\Documentos\FormatoDocumento', 'F')
    @if ($anulado)
        <div class="recuadro">RECIBO ANULADO<br>{{ $pago->motivo_anulacion ?? '-' }}</div>
    @elseif ($copia)
        <div class="recuadro">COPIA</div>
    @endif

    @include('pdf.creditos._cabecera_80mm', ['titulo' => 'RECIBO DE PAGO', 'numero' => $pago->numero_recibo])

    <div>Fecha: {{ $pago->fecha_pago->format('d/m/Y H:i') }}</div>
    <div>Cliente: {{ $credito->cliente?->full_name }}</div>
    <div>{{ $credito->cliente?->type_document }}: {{ $credito->cliente?->n_document }}</div>
    <div>Crédito: {{ $credito->numero_credito }}</div>
    <div>Concepto: {{ $origen }}</div>
    <div>Método: {{ $metodo ?? '—' }}</div>
    @if ($pago->referencia)
        <div>N.º op.: {{ $pago->referencia }}</div>
    @endif

    <div class="linea"></div>
    <table>
        @foreach ($lineas as $linea)
            @foreach ($linea['conceptos'] as $concepto => $monto)
                <tr>
                    <td>{{ $linea['numero_cuota'] ? 'Cuota ' . $linea['numero_cuota'] . ' ' : '' }}{{ $conceptos[$concepto] ?? $concepto }}</td>
                    <td class="derecha">{{ $monto }}</td>
                </tr>
            @endforeach
        @endforeach
    </table>
    <div class="linea"></div>
    <table>
        <tr><td>Recibido</td><td class="derecha">{{ F::soles((string) $pago->monto_recibido) }}</td></tr>
        @if ((string) $pago->monto_excedente !== '0.00')
            <tr><td>{{ $destino === 'saldo_a_favor' ? 'A saldo a favor' : 'Vuelto' }}</td><td class="derecha">{{ F::soles((string) $pago->monto_excedente) }}</td></tr>
        @endif
        <tr class="negrita"><td>TOTAL APLICADO</td><td class="derecha">{{ F::soles((string) $pago->monto_aplicado) }}</td></tr>
        @if ($despues)
            <tr><td>Saldo pendiente</td><td class="derecha">{{ F::soles($despues->porPagar()) }}</td></tr>
            @if ($despues->proxima)
                <tr><td>Próximo pago</td><td class="derecha">{{ F::fechaTexto($despues->proxima->fechaVencimiento) }}</td></tr>
                <tr><td></td><td class="derecha">{{ F::soles($despues->proxima->pendiente) }}</td></tr>
            @endif
        @endif
    </table>
    <div class="linea"></div>
    <div>Atendido por: {{ $cajero ?? '—' }}</div>
    <div class="leyenda">Documento interno de control de pago.<br>No es comprobante de pago electrónico.</div>
</body>
</html>
