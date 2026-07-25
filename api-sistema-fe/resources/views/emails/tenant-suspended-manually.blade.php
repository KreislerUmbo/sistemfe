<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #222;">
    <p>Hola {{ $razon_social }},</p>

    <p>Tu cuenta ha sido <strong>suspendida</strong>.</p>

    @if (!empty($motivo))
        <p><strong>Motivo:</strong> {{ $motivo }}</p>
    @endif

    <p>
        Mientras la cuenta esté suspendida, no podrás acceder al panel administrativo ni a
        tu tienda en línea. Contacta al administrador de la plataforma para más
        información.
    </p>
</body>
</html>
