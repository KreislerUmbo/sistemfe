<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Estado de cuenta {{ $credito->numero_credito }}</title>
    @include('pdf.creditos._estilos_a4')
</head>
<body>
    @use('App\Services\Creditos\Documentos\FormatoDocumento', 'F')
    @include('pdf.creditos._cabecera_a4', ['titulo' => 'Estado de cuenta', 'numero' => $credito->numero_credito])
    @include('pdf.creditos._datos_credito_a4')

    @if ($detalle->saldo)
        <table class="totales" style="width: 100%; margin-top: 0;">
            <tr>
                <td>Pagado: <span class="negrita">{{ F::soles($detalle->saldo->totalPagado) }}</span></td>
                <td>Mora pendiente: <span class="negrita {{ $detalle->saldo->mora > 0 ? 'rojo' : '' }}">{{ F::soles($detalle->saldo->mora) }}</span></td>
                <td>Exigible hoy: <span class="negrita">{{ F::soles($detalle->exigible) }}</span></td>
                <td class="derecha">Saldo por pagar: <span class="negrita">{{ F::soles($detalle->saldo->porPagar()) }}</span></td>
            </tr>
        </table>
    @endif

    <div class="seccion">Cuotas</div>
    <table class="tabla">
        <thead>
            <tr>
                <th style="width: 6%;">N.º</th><th>Vence</th><th class="derecha">Cuota</th><th class="derecha">Pagado</th>
                <th class="derecha">Mora hoy</th><th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $fila)
                <tr class="{{ $fila['vencida'] ? 'fila-vencida' : '' }}">
                    <td>{{ $fila['numero'] }}</td>
                    <td>{{ $fila['vence'] }}</td>
                    <td class="derecha">{{ $fila['total'] }}{{ $fila['cargo'] ? ' + cargo ' . $fila['cargo'] : '' }}</td>
                    <td class="derecha">{{ $fila['pagado'] }}</td>
                    <td class="derecha {{ $fila['mora'] !== '—' ? 'rojo' : '' }}">{{ $fila['mora'] }}</td>
                    <td>{{ $fila['estado'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="seccion">Pagos</div>
    @if ($cuenta->pagos->isEmpty())
        <p class="apagado">Todavía no hay pagos.</p>
    @else
        <table class="tabla">
            <thead>
                <tr><th>Recibo</th><th>Fecha</th><th>Método</th><th>Aplicado a</th><th class="derecha">Importe</th></tr>
            </thead>
            <tbody>
                @foreach ($cuenta->pagos as $pago)
                    @php $anulado = F::valor($pago->estado) === 'anulado'; @endphp
                    <tr class="{{ $anulado ? 'apagado' : '' }}">
                        <td>{{ $pago->numero_recibo }}{{ $anulado ? ' (anulado)' : '' }}</td>
                        <td>{{ $pago->fecha_pago->format('d/m/Y') }}</td>
                        <td>{{ $metodos[$pago->payment_method_id] ?? '—' }}</td>
                        <td>
                            @unless ($anulado)
                                {{ $pago->aplicaciones->map(fn ($a) => 'Cuota ' . ($a->cuota?->numero_cuota ?? '-') . ' ' . mb_strtolower($conceptos[F::valor($a->concepto)] ?? F::valor($a->concepto)))->unique()->implode(', ') }}
                            @endunless
                        </td>
                        <td class="derecha">{{ $anulado ? '' : F::soles((string) $pago->monto_aplicado) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($cuenta->condonaciones->isNotEmpty() || $cuenta->reprogramaciones->isNotEmpty())
        <div class="seccion">Otros movimientos</div>
        <table class="tabla">
            <thead><tr><th>Fecha</th><th>Movimiento</th><th>Detalle</th><th class="derecha">Monto</th></tr></thead>
            <tbody>
                @foreach ($cuenta->condonaciones as $c)
                    <tr>
                        <td>{{ $c->created_at?->format('d/m/Y') }}</td>
                        <td>Condonación de mora{{ F::valor($c->estado) !== 'vigente' ? ' (anulada)' : '' }}</td>
                        <td>Cuota {{ $c->cuota?->numero_cuota }} · {{ $c->motivo }}</td>
                        <td class="derecha">{{ F::soles((string) $c->monto) }}</td>
                    </tr>
                @endforeach
                @foreach ($cuenta->reprogramaciones as $r)
                    <tr>
                        <td>{{ $r->created_at?->format('d/m/Y') }}</td>
                        <td>Reprogramación de fechas</td>
                        <td>{{ $r->cuotas->count() }} cuota(s) · {{ $r->motivo }}</td>
                        <td class="derecha">{{ (string) $r->cargo_monto !== '0.00' ? 'Cargo ' . F::soles((string) $r->cargo_monto) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
