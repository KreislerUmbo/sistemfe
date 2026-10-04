@use('App\Services\Creditos\Documentos\FormatoDocumento', 'F')
{{-- Datos del cliente y condiciones del crédito (cronograma, estado de cuenta, constancia, acuerdo). --}}
<table class="info">
    <tr><td class="titulo" colspan="4">Cliente y crédito</td></tr>
    <tr>
        <td style="width: 18%;">Cliente:</td><td style="width: 40%;" class="negrita">{{ $credito->cliente?->full_name }}</td>
        <td style="width: 18%;">Entrega:</td><td>{{ F::fecha($credito->fecha_desembolso) }}</td>
    </tr>
    <tr>
        <td>Documento:</td><td>{{ $credito->cliente?->type_document }} {{ $credito->cliente?->n_document }}</td>
        <td>Prestado:</td><td>{{ F::soles((string) $credito->monto_capital) }}</td>
    </tr>
    <tr>
        <td>Teléfono:</td><td>{{ $credito->cliente?->phone ?? '—' }}</td>
        <td>Interés:</td><td>{{ F::tasa($credito) }} ({{ F::soles((string) $credito->interes_total) }})</td>
    </tr>
    <tr>
        <td style="padding-bottom: 8px;">Forma de pago:</td>
        <td style="padding-bottom: 8px;">{{ $credito->numero_cuotas }} pagos, {{ F::frecuencia($credito) }}</td>
        <td style="padding-bottom: 8px;">Total a devolver:</td>
        <td style="padding-bottom: 8px;" class="negrita">{{ F::totalCredito($credito) }}</td>
    </tr>
</table>
