@if ($prueba)
[Correo de prueba de la configuración]

@endif
{!! $mensaje !!}
@if (count($adjuntos) > 0)

Adjuntos: {{ implode(' · ', $adjuntos) }}
@endif

Enviado por {{ $agencia }}
