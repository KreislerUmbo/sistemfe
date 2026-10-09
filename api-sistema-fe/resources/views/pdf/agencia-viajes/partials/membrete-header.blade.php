{{-- ══════════════════ HEADER FIJO ══════════════════ --}}
{{-- Membrete compartido (cotización + condiciones generales, 09-oct-2026).
     Hallazgo del usuario (06-sep-2026): va como hermano de .documento, con
     position:fixed (ver membrete-estilos), y se repite en cada página
     automáticamente (motor de DomPDF). --}}
<div class="header-fijo">
    {{-- Override total (plan §4.2): si la agencia cargó su propio
         membrete diseñado (ej. DKM Xplore), se usa tal cual a ancho
         completo y se ignoran logo/colores/contacto de abajo para esta
         zona. Sin override, se genera desde Company + configPdf. --}}
    @if (!empty($headerCustomUrl))
        <img src="{{ $headerCustomUrl }}">
    @else
        <div class="header-generado">
            <table style="width:100%;" class="header-wrap">
                <tr>
                    <td style="width:170px; vertical-align:top;">
                        @if (!empty($logoUrl))
                            <img src="{{ $logoUrl }}" style="max-width:170px; max-height:70px;">
                        @else
                            <div class="logo-box">LOGO</div>
                        @endif
                    </td>
                    <td style="vertical-align:top; padding:0 16px;">
                        <div class="empresa-nombre" style="color: {{ $configPdf->color_primario }};">{{ $empresa->razon_social_comercial ?? $empresa->razon_social ?? '' }}</div>
                        <div class="empresa-datos">
                            RUC: {{ $empresa->n_document ?? '-' }}<br>
                            Teléfono: {{ $empresa->phone ?? '-' }} &nbsp;·&nbsp; Email: {{ $empresa->email ?? '-' }}
                        </div>
                        @if (!empty($configPdf->eslogan))
                            <div class="empresa-datos" style="font-style: italic; color: {{ $configPdf->color_secundario }};">{{ $configPdf->eslogan }}</div>
                        @endif
                    </td>
                </tr>
            </table>
            {{-- Franja de afiliaciones (Mincetur/Apavit/PromPerú) — solo si
                 mostrar_afiliaciones=true Y la agencia marcó al menos una
                 (MembreteAgenciaPdfService::afiliacionesParaMostrar()). Un
                 catálogo sin logo_path cargado cae al nombre en texto. --}}
            @if (count($afiliaciones) > 0)
                <div class="afiliaciones-franja">
                    @foreach ($afiliaciones as $afiliacion)
                        @if (!empty($afiliacion['logo']))
                            <img src="{{ $afiliacion['logo'] }}">
                        @else
                            <strong>{{ $afiliacion['nombre'] }}</strong>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
