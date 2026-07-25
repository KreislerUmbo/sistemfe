<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #222;">
    <p>Hola {{ $razon_social }},</p>

    <p>
        Tu comprobante <strong>{{ $folio_interno }}</strong> por
        <strong>S/ {{ number_format((float) $monto, 2) }}</strong>, con vencimiento el
        {{ $fecha_vencimiento }}, aún no registra pago.
    </p>

    <p>
        Tienes <strong>{{ $dias_gracia }} días</strong> desde el vencimiento para regularizar
        el pago antes de que tu cuenta sea suspendida.
    </p>

    <p>Si ya realizaste el pago, puedes ignorar este mensaje.</p>
</body>
</html>
