<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #222;">
    <p>Hola {{ $razon_social }},</p>

    <p>
        Tu cuenta ha sido <strong>suspendida</strong> por falta de pago del comprobante
        <strong>{{ $folio_interno }}</strong>, vencido desde el {{ $fecha_vencimiento }}.
    </p>

    <p>
        Mientras la cuenta esté suspendida, no podrás acceder al panel administrativo ni a
        tu tienda en línea.
    </p>

    <p>Contacta al administrador de la plataforma para regularizar el pago y reactivar tu cuenta.</p>
</body>
</html>
