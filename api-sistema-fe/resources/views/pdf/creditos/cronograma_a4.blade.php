<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cronograma {{ $credito->numero_credito }}</title>
    @include('pdf.creditos._estilos_a4')
</head>
<body>
    @use('App\Services\Creditos\Documentos\FormatoDocumento', 'F')
    @include('pdf.creditos._cabecera_a4', ['titulo' => 'Cronograma de pagos', 'numero' => $credito->numero_credito])
    @include('pdf.creditos._datos_credito_a4')

    <table class="tabla">
        <thead>
            <tr>
                <th style="width: 6%;">N.º</th><th>Vence</th><th class="derecha">Capital</th><th class="derecha">Interés</th>
                <th class="derecha">Cuota</th><th class="derecha">Pagado</th><th class="derecha">Mora hoy</th><th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $fila)
                <tr class="{{ $fila['vencida'] ? 'fila-vencida' : '' }}">
                    <td>{{ $fila['numero'] }}</td>
                    <td>{{ $fila['vence'] }}</td>
                    <td class="derecha">{{ $fila['capital'] }}</td>
                    <td class="derecha">{{ $fila['interes'] }}</td>
                    <td class="derecha negrita">{{ $fila['total'] }}{{ $fila['cargo'] ? ' + cargo ' . $fila['cargo'] : '' }}</td>
                    <td class="derecha">{{ $fila['pagado'] }}</td>
                    <td class="derecha {{ $fila['mora'] !== '—' ? 'rojo' : '' }}">{{ $fila['mora'] }}</td>
                    <td>{{ $fila['estado'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($detalle->saldo)
        <table class="totales">
            <tr><td>Pagado a la fecha</td><td class="derecha">{{ F::soles($detalle->saldo->totalPagado) }}</td></tr>
            <tr><td>Interés moratorio pendiente</td><td class="derecha">{{ F::soles($detalle->saldo->mora) }}</td></tr>
            <tr class="final"><td>Saldo por pagar</td><td class="derecha">{{ F::soles($detalle->saldo->porPagar()) }}</td></tr>
        </table>
    @endif
</body>
</html>
