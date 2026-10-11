{{-- Fase 5b: QR "Mi crédito" (el cliente consulta su crédito). $miCredito = QrMiCredito::paraCliente()
     (null → no se imprime nada). $formato: 'ticket' (80 mm, centrado) o 'a4' (caja con el texto al lado).
     $ejemplo = true en la vista previa del contrato: un recuadro en lugar del QR, sin crear ningún link. --}}
@if (! empty($ejemplo))
    <table style="width: 100%; margin-top: 18px; border: 1px dashed #999999;">
        <tr>
            <td style="width: 86px; height: 86px; text-align: center; font-size: 9px; color: #666666;">[QR Mi crédito]</td>
            <td style="font-size: 10px; padding-left: 10px;">
                <strong>Consulta tu crédito en cualquier momento</strong><br>
                Aquí va el QR del cliente: con él ve sus cuotas, cuánto le falta y sus pagos.
            </td>
        </tr>
    </table>
@elseif (! empty($miCredito))
    @if (($formato ?? 'a4') === 'ticket')
        <div class="linea"></div>
        <div class="centro" style="margin-top: 4px;">
            <div style="font-size: 8px; font-weight: bold;">Consulta tu crédito</div>
            <img src="{{ $miCredito['qr'] }}" alt="QR Mi crédito" style="width: 92px; height: 92px;">
            <div style="font-size: 7px; word-break: break-all;">{{ $miCredito['texto'] }}</div>
        </div>
    @else
        <table style="width: 100%; margin-top: 18px; border: 1px solid #dddddd;">
            <tr>
                <td style="width: 96px; padding: 6px;"><img src="{{ $miCredito['qr'] }}" alt="QR Mi crédito" style="width: 86px; height: 86px;"></td>
                <td style="font-size: 10px; padding: 6px;">
                    <strong>Consulta tu crédito en cualquier momento</strong><br>
                    Escanea el código con la cámara de tu celular: verás tu próxima cuota, cuánto te falta y tus pagos.
                    Te pediremos los últimos 4 dígitos de tu DNI.<br>
                    <span style="color: #555555;">{{ $miCredito['texto'] }}</span>
                </td>
            </tr>
        </table>
    @endif
@endif
