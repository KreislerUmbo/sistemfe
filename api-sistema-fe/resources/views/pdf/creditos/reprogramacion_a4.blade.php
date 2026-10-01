<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Acuerdo de reprogramación {{ $credito->numero_credito }}</title>
    @include('pdf.creditos._estilos_a4')
</head>
<body>
    @use('App\Services\Creditos\Documentos\FormatoDocumento', 'F')
    @include('pdf.creditos._cabecera_a4', ['titulo' => 'Acuerdo de reprogramación', 'numero' => $credito->numero_credito])
    @include('pdf.creditos._datos_credito_a4')

    @php
        $accionMora = F::valor($reprogramacion->accion_mora);
        $cargoTipo = F::valor($reprogramacion->cargo_tipo);
    @endphp

    <p style="font-size: 11px; line-height: 1.6;">
        El {{ $reprogramacion->created_at?->format('d/m/Y') }}, a solicitud del cliente, se acuerda cambiar las fechas de pago
        de las cuotas indicadas abajo. Los montos de las cuotas no cambian.
        <br>Motivo: {{ $reprogramacion->motivo }}
    </p>

    <table class="tabla">
        <thead>
            <tr><th style="width: 12%;">Cuota</th><th>Fecha anterior</th><th>Fecha nueva</th><th class="derecha">Cuota</th><th class="derecha">Mora mantenida</th></tr>
        </thead>
        <tbody>
            @foreach ($reprogramacion->cuotas->sortBy(fn ($c) => $c->cuota?->numero_cuota) as $cambio)
                <tr>
                    <td>N.º {{ $cambio->cuota?->numero_cuota }}</td>
                    <td>{{ F::fecha($cambio->fecha_anterior) }}</td>
                    <td class="negrita">{{ F::fecha($cambio->fecha_nueva) }}</td>
                    <td class="derecha">{{ $cambio->cuota ? F::soles((string) $cambio->cuota->monto_total) : '—' }}</td>
                    <td class="derecha">{{ (string) $cambio->mora_congelada !== '0.00' ? F::soles((string) $cambio->mora_congelada) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="font-size: 11px; line-height: 1.6; margin-top: 12px;">
        @if ($accionMora === 'mantener')
            El interés moratorio acumulado hasta hoy se mantiene y deberá pagarse junto con las cuotas.
        @elseif ($accionMora === 'condonar')
            El interés moratorio acumulado hasta hoy fue condonado.
        @endif
        @if ($cargoTipo !== 'ninguno' && (string) $reprogramacion->cargo_monto !== '0.00')
            Se aplica un cargo por reprogramación de {{ F::soles((string) $reprogramacion->cargo_monto) }}, que se suma a la primera cuota reprogramada.
        @endif
    </p>

    <table class="firmas">
        <tr>
            <td><div class="linea">{{ $credito->cliente?->full_name }}<br>{{ $credito->cliente?->type_document }} {{ $credito->cliente?->n_document }}</div></td>
            <td><div class="linea">{{ $empresa?->razon_social ?? '' }}<br>{{ $usuario ?? '' }}</div></td>
        </tr>
    </table>
</body>
</html>
