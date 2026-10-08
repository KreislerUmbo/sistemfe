<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo {{ $pago->numero_recibo }}</title>
    @include('pdf.creditos._estilos_a4')
</head>
<body>
    @use('App\Services\Creditos\Documentos\FormatoDocumento', 'F')
    @if ($copia && !$anulado)
        <div class="marca-copia">COPIA</div>
    @endif

    @include('pdf.creditos._cabecera_a4', ['titulo' => 'Recibo de pago', 'numero' => $pago->numero_recibo])

    @if ($anulado)
        <div class="caja-anulado">RECIBO ANULADO — {{ $pago->motivo_anulacion ?? 'sin motivo' }}</div>
    @endif

    <table class="info">
        <tr><td class="titulo" colspan="4">Datos del pago</td></tr>
        <tr>
            <td style="width: 18%;">Cliente:</td><td style="width: 42%;" class="negrita">{{ $credito->cliente?->full_name }}</td>
            <td style="width: 16%;">Fecha:</td><td>{{ $pago->fecha_pago->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td>Documento:</td><td>{{ $credito->cliente?->type_document }} {{ $credito->cliente?->n_document }}</td>
            <td>Crédito:</td><td>{{ $credito->numero_credito }}</td>
        </tr>
        <tr>
            <td>Concepto:</td><td>{{ $origen }}</td>
            <td>Método:</td><td>{{ $metodo ?? '—' }}{{ $pago->referencia ? ' · Op. ' . $pago->referencia : '' }}</td>
        </tr>
        <tr><td style="padding-bottom: 8px;">Atendido por:</td><td colspan="3" style="padding-bottom: 8px;">{{ $cajero ?? '—' }}</td></tr>
    </table>

    <table class="tabla">
        <thead>
            <tr><th style="width: 18%;">Cuota</th><th>Concepto</th><th class="derecha" style="width: 22%;">Importe</th></tr>
        </thead>
        <tbody>
            @foreach ($lineas as $linea)
                @foreach ($linea['conceptos'] as $concepto => $monto)
                    <tr>
                        <td>{{ $linea['numero_cuota'] ? 'N.º ' . $linea['numero_cuota'] : '—' }}</td>
                        <td>{{ $conceptos[$concepto] ?? $concepto }}</td>
                        <td class="derecha">{{ $monto }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        @if ($renovacion)
            <tr><td>Cubierto con el crédito {{ $renovacion['credito'] ?? 'nuevo' }}</td><td class="derecha">{{ $renovacion['cubierto'] }}</td></tr>
            <tr><td>Pagado por el cliente</td><td class="derecha">{{ $renovacion['cliente'] }}</td></tr>
        @else
            <tr><td>Recibido</td><td class="derecha">{{ F::soles((string) $pago->monto_recibido) }}</td></tr>
        @endif
        @if ((string) $pago->monto_excedente !== '0.00')
            <tr><td>{{ $destino === 'saldo_a_favor' ? 'A saldo a favor' : 'Vuelto' }}</td><td class="derecha">{{ F::soles((string) $pago->monto_excedente) }}</td></tr>
        @endif
        <tr class="final"><td>Total aplicado al crédito</td><td class="derecha">{{ F::soles((string) $pago->monto_aplicado) }}</td></tr>
        @if ($despues)
            <tr><td>Saldo pendiente tras este pago</td><td class="derecha">{{ F::soles($despues->porPagar()) }}</td></tr>
            @if ($despues->proxima)
                <tr><td>Próximo pago</td><td class="derecha">{{ F::fechaTexto($despues->proxima->fechaVencimiento) }} · {{ F::soles($despues->proxima->pendiente) }}</td></tr>
            @endif
        @endif
    </table>

    <p class="apagado" style="margin-top: 24px; font-size: 10px;">Son: {{ F::enLetras((string) $pago->monto_aplicado) }}.
        Documento interno de control de pago; no es comprobante de pago electrónico.</p>
</body>
</html>
