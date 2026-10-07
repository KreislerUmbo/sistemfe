{{-- Cabecera A4: logo + empresa a la izquierda, recuadro con el tipo y número del documento a la derecha. --}}
<table class="cabecera">
    <tr>
        <td style="width: 62%; vertical-align: top;">
            @if (!empty($logo))
                <img src="{{ $logo }}" style="max-width: 180px; max-height: 60px; margin-bottom: 6px;"><br>
            @endif
            <div class="empresa-nombre">{{ $empresa?->razon_social_comercial ?: $empresa?->razon_social }}</div>
            <div class="empresa-datos">
                @if ($empresa?->razon_social_comercial && $empresa?->razon_social)
                    {{ $empresa->razon_social }}<br>
                @endif
                RUC: {{ $empresa?->n_document ?? '—' }}<br>
                {{ $empresa?->address }}
                @if ($empresa?->phone)
                    <br>Tel.: {{ $empresa->phone }}
                @endif
            </div>
        </td>
        <td style="width: 38%; vertical-align: top;">
            <table class="doc-box">
                <tr>
                    <td>
                        <div class="tipo">{{ $titulo }}</div>
                        @if (!empty($numero))
                            <div class="numero">{{ $numero }}</div>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
<div class="pie">Generado el {{ \App\Services\HoraPeru::ahora()->format('d/m/Y H:i') }}</div>
