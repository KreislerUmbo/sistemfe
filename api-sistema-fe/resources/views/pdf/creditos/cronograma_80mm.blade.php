<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cronograma {{ $credito->numero_credito }}</title>
    @include('pdf.creditos._estilos_80mm')
</head>
<body>
    @use('App\Services\Creditos\Documentos\FormatoDocumento', 'F')
    @include('pdf.creditos._cabecera_80mm', ['titulo' => 'CRONOGRAMA DE PAGOS', 'numero' => $credito->numero_credito])

    <div>Cliente: {{ $credito->cliente?->full_name }}</div>
    <div>Prestado: {{ F::soles((string) $credito->monto_capital) }}</div>
    <div>Total: {{ F::totalCredito($credito) }}</div>
    <div>{{ $credito->numero_cuotas }} pagos, {{ F::frecuencia($credito) }}</div>
    <div class="linea"></div>
    <table>
        <tr class="negrita"><td>N.º</td><td>Vence</td><td class="derecha">Cuota</td><td class="derecha">Estado</td></tr>
        @foreach ($filas as $fila)
            <tr>
                <td>{{ $fila['numero'] }}</td>
                <td>{{ $fila['vence'] }}</td>
                <td class="derecha">{{ $fila['total'] }}</td>
                <td class="derecha">{{ $fila['estado'] }}</td>
            </tr>
        @endforeach
    </table>
    @if ($detalle->saldo)
        <div class="linea"></div>
        <table>
            <tr><td>Mora pendiente</td><td class="derecha">{{ F::soles($detalle->saldo->mora) }}</td></tr>
            <tr class="negrita"><td>SALDO POR PAGAR</td><td class="derecha">{{ F::soles($detalle->saldo->porPagar()) }}</td></tr>
        </table>
    @endif
    <div class="leyenda">Generado el {{ \App\Services\HoraPeru::ahora()->format('d/m/Y H:i') }}</div>
</body>
</html>
