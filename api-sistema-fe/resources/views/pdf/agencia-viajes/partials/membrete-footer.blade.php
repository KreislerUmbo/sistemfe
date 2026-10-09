{{-- ══════════════════ FOOTER FIJO ══════════════════ --}}
{{-- Membrete compartido (cotización + condiciones generales, 09-oct-2026).
     Hallazgo del usuario (06-sep-2026): mismo problema y mismo fix que el
     header — position:fixed, hermano de .documento, se repite en cada
     página. La línea legal ($textoLegal) queda SIEMPRE visible (no es
     branding) con el padding normal de 12mm; el membrete custom o las
     redes sociales van debajo. --}}
<div class="footer-fijo">
    <div class="footer-generado">
        <div class="footer-legal">
            {{ $textoLegal }}
        </div>
    </div>

    {{-- Override total (plan §4.2): mismo criterio que el header — si
         hay footer_custom cargado, se usa tal cual (bleed) y se
         ignoran las redes sociales de abajo. --}}
    @if (!empty($footerCustomUrl))
        <img src="{{ $footerCustomUrl }}" style="margin-top:4px;">
    @elseif (!empty($configPdf->redes_sociales))
        <div class="footer-generado">
            <div class="footer-marca">
                @foreach ($configPdf->redes_sociales as $red)
                    {{ ucfirst($red['red']) }}: {{ $red['usuario'] }}{!! !$loop->last ? ' &nbsp;·&nbsp; ' : '' !!}
                @endforeach
            </div>
        </div>
    @endif
</div>
