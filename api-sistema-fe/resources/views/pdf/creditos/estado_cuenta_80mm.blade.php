<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Estado de cuenta {{ $credito->numero_credito }}</title>
    @include('pdf.creditos._estilos_80mm')
</head>
<body>
    @use('App\Services\Creditos\Documentos\FormatoDocumento', 'F')
    @include('pdf.creditos._cabecera_80mm', ['titulo' => 'ESTADO DE CUENTA', 'numero' => $credito->numero_credito])

    <div>Cliente: {{ $credito->cliente?->full_name }}</div>
    <div>{{ $credito->cliente?->type_document }}: {{ $credito->cliente?->n_document }}</div>
    <div>Total del crédito: {{ F::totalCredito($credito) }}</div>
    <div class="linea"></div>
    <table>
        <tr class="negrita"><td>N.º</td><td>Vence</td><td class="derecha">Cuota</td><td class="derecha">Estado</td></tr>
        @foreach ($filas as $fila)
            <tr>
                <td>{{ $fila['numero'] }}</td>
                <td>{{ $fila['vence'] }}</td>
                <td class="derecha">{{ $fila['total'] }}</td>
                <td class="derecha">{{ $fila['estado'] }}{{ $fila['mora'] !== '—' ? '*' : '' }}</td>
            </tr>
        @endforeach
    </table>
    <div class="linea"></div>
    <div class="negrita">Pagos</div>
    <table>
        @forelse ($cuenta->pagos->filter(fn ($p) => F::valor($p->estado) !== 'anulado') as $pago)
            <tr><td>{{ $pago->fecha_pago->format('d/m/Y') }} {{ $pago->numero_recibo }}</td><td class="derecha">{{ F::soles((string) $pago->monto_aplicado) }}</td></tr>
        @empty
            <tr><td>Sin pagos</td><td></td></tr>
        @endforelse
    </table>
    @if ($detalle->saldo)
        <div class="linea"></div>
        <table>
            <tr><td>Pagado</td><td class="derecha">{{ F::soles($detalle->saldo->totalPagado) }}</td></tr>
            <tr><td>Mora pendiente*</td><td class="derecha">{{ F::soles($detalle->saldo->mora) }}</td></tr>
            <tr><td>Exigible hoy</td><td class="derecha">{{ F::soles($detalle->exigible) }}</td></tr>
            <tr class="negrita"><td>SALDO POR PAGAR</td><td class="derecha">{{ F::soles($detalle->saldo->porPagar()) }}</td></tr>
        </table>
    @endif
    <div class="leyenda">* Cuota con interés moratorio pendiente.<br>Generado el {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
