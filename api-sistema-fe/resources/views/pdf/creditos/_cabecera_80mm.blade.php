{{-- Cabecera de ticket 80 mm: logo, empresa y título del documento. --}}
@if (!empty($logo))
    <div class="centro" style="margin-bottom: 4px;"><img src="{{ $logo }}" style="max-width: 150px; max-height: 60px;"></div>
@endif
<div class="centro">
    <div class="titulo">{{ $empresa?->razon_social_comercial ?: $empresa?->razon_social }}</div>
    <div>RUC: {{ $empresa?->n_document ?? '—' }}</div>
    <div>{{ $empresa?->address }}</div>
</div>
<div class="linea"></div>
<div class="centro negrita">{{ $titulo }}</div>
@if (!empty($numero))
    <div class="centro negrita">{{ $numero }}</div>
@endif
<div class="linea"></div>
