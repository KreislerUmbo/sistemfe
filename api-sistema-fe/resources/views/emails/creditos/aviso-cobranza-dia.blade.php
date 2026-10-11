{{-- Módulo Créditos (Fase 5): aviso diario de cobranza. Estilos en línea (clientes de correo). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 16px; background: #f4f5f7; font-family: Arial, sans-serif; color: #222222;">
<div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 20px;">
    @if ($prueba)
        <p style="margin: 0 0 12px; padding: 8px; background: #fff4d6; border-radius: 4px; font-size: 13px;">
            Correo de prueba: así se verá tu aviso de cada mañana.
        </p>
    @endif

    <p style="margin: 0 0 4px; font-size: 13px; color: #666666;">{{ $empresa }} · {{ ucfirst($fechaLarga) }}</p>
    <h1 style="margin: 0 0 16px; font-size: 20px;">Hola {{ $usuario }}, esto es lo que hay para cobrar hoy</h1>

    @if ($resumen['cuotas'] > 0)
        <table role="presentation" style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
            <tr>
                <td style="padding: 10px; background: #f2f6ff; border-radius: 6px 0 0 6px; text-align: center;">
                    <div style="font-size: 22px; font-weight: bold;">{{ $resumen['cuotas'] }}</div>
                    <div style="font-size: 12px; color: #555555;">cuotas · {{ $resumen['clientes'] }} {{ $resumen['clientes'] === 1 ? 'cliente' : 'clientes' }}</div>
                </td>
                <td style="padding: 10px; background: #f2f6ff; text-align: center;">
                    <div style="font-size: 22px; font-weight: bold;">S/ {{ $monto }}</div>
                    <div style="font-size: 12px; color: #555555;">por cobrar (cuotas + mora)</div>
                </td>
                <td style="padding: 10px; background: {{ $resumen['atrasados'] > 0 ? '#fdecec' : '#f2f6ff' }}; border-radius: 0 6px 6px 0; text-align: center;">
                    <div style="font-size: 22px; font-weight: bold;">{{ $resumen['atrasados'] }}</div>
                    <div style="font-size: 12px; color: #555555;">atrasadas</div>
                </td>
            </tr>
        </table>

        @if (count($cobradores) > 0)
            <p style="margin: 0 0 6px; font-weight: bold; font-size: 14px;">Por cobrador</p>
            <table role="presentation" style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 13px;">
                @foreach ($cobradores as $c)
                    <tr>
                        <td style="padding: 6px 0; border-bottom: 1px solid #eeeeee;">{{ $c['cobrador'] }}</td>
                        <td style="padding: 6px 0; border-bottom: 1px solid #eeeeee; text-align: right;">{{ $c['cuotas'] }} {{ $c['cuotas'] === 1 ? 'cuota' : 'cuotas' }}</td>
                        <td style="padding: 6px 0; border-bottom: 1px solid #eeeeee; text-align: right;">S/ {{ $c['monto'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if ($conAdjunto)
            <p style="margin: 0 0 16px; font-size: 13px;">Adjuntamos la hoja de ruta en PDF (un cobrador por página), generada a esta hora.</p>
        @endif
    @else
        <p style="margin: 0 0 16px;">No hay cuotas programadas ni atrasadas para hoy.</p>
    @endif

    <p style="margin: 0 0 16px;">
        <a href="{{ $urlAgenda }}" style="display: inline-block; padding: 10px 16px; background: #2f6fed; color: #ffffff; text-decoration: none; border-radius: 6px;">
            Abrir la Agenda de cobranza
        </a>
    </p>

    <p style="margin: 0; font-size: 12px; color: #777777;">
        Los montos son de la hora en que salió este correo; si un cliente ya pagó, la Agenda lo muestra al día.
        Este correo es confidencial: contiene datos de clientes. Para dejar de recibirlo, apágalo en tus preferencias de avisos.
    </p>
</div>
</body>
</html>
