<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #222;">
    <p>Hola {{ $razon_social }},</p>

    <p>
        Tu comprobante <strong>{{ $folio_interno }}</strong> por
        <strong>S/ {{ number_format((float) $monto, 2) }}</strong> sigue pendiente de pago
        desde el {{ $fecha_vencimiento }}.
    </p>

    <p>
        Quedan aproximadamente <strong>{{ $dias_restantes }} días</strong> antes de que tu
        cuenta sea suspendida por falta de pago.
    </p>

    <p>Regulariza el pago lo antes posible para evitar la suspensión del servicio.</p>
</body>
</html>
