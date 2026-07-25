<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #222;">
    <p>El backup automático del tenant <strong>{{ $razon_social }}</strong> ({{ $tenant_id }}) falló.</p>

    <p><strong>Error:</strong></p>
    <pre style="background: #f5f5f5; padding: 12px; white-space: pre-wrap;">{{ $mensaje }}</pre>

    <p>Revisar el registro en <code>tenant_backups</code> y correr un backup manual si hace falta.</p>
</body>
</html>
