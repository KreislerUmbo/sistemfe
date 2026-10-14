{{-- Agencia de Viajes: cotización enviada al cliente. El mensaje es texto plano
     del usuario: se escapa con {{ }} y solo se convierten los saltos de línea. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 16px; background: #f4f5f7; font-family: Arial, sans-serif; color: #222222;">
<div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 24px;">
    @if ($prueba)
        <p style="margin: 0 0 16px; padding: 8px 12px; background: #fff4d6; border-radius: 6px; font-size: 13px; color: #6b5200;">
            Este es un correo de prueba de la configuración. Así lo recibirá tu cliente.
        </p>
    @endif
    <div style="font-size: 14px; line-height: 1.6;">{!! nl2br(e($mensaje)) !!}</div>
    @if (count($adjuntos) > 0)
        <p style="margin: 20px 0 0; padding-top: 12px; border-top: 1px solid #eeeeee; font-size: 12px; color: #777777;">
            Adjuntos: {{ implode(' · ', $adjuntos) }}
        </p>
    @endif
</div>
<p style="max-width: 600px; margin: 8px auto 0; font-size: 11px; color: #999999; text-align: center;">
    Enviado por {{ $agencia }}
</p>
</body>
</html>
