{{-- Módulo Créditos (Fase 5): confirmación del correo para avisos. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 16px; background: #f4f5f7; font-family: Arial, sans-serif; color: #222222;">
<div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 20px;">
    <p style="margin: 0 0 4px; font-size: 13px; color: #666666;">{{ $empresa }}</p>
    <h1 style="margin: 0 0 16px; font-size: 20px;">Hola {{ $usuario }}, confirma este correo</h1>
    <p style="margin: 0 0 16px;">
        Pediste recibir aquí el aviso diario de cobranza. Como ese aviso lleva datos de clientes,
        solo lo enviamos a un correo confirmado.
    </p>
    <p style="margin: 0 0 16px;">
        <a href="{{ $url }}" style="display: inline-block; padding: 10px 16px; background: #2f6fed; color: #ffffff; text-decoration: none; border-radius: 6px;">
            Confirmar mi correo
        </a>
    </p>
    <p style="margin: 0; font-size: 12px; color: #777777;">El enlace vence en 48 horas. Si no lo pediste tú, ignora este correo.</p>
</div>
</body>
</html>
