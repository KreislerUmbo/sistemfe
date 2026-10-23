<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaItemVueloPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SalidaMayorista;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Quitar un pasajero de una reserva aceptada: reglas y limpieza. Salió de
// ReservaPasajeroController::destroy() en F4 etapa 1 (23-oct-2026): ese
// endpoint quedó bloqueado porque quitar un pasajero sin tocar el precio
// descuadra la reserva (en un tour, el último grupo pagaba su parte). La
// etapa 2 ("Modificar reserva") lo usa al aplicar la diferencia, junto con
// el recálculo y la penalidad. Rechaza con HttpException(422) y un mensaje
// para el usuario.
class QuitarPasajeroReservaService
{
    public function quitar(ReservaPasajero $pasajero): void
    {
        $reserva = $pasajero->reserva;

        if ($reserva->estado !== 'activa') {
            throw new HttpException(422, 'Solo se puede quitar un pasajero de una reserva activa.');
        }

        DB::transaction(function () use ($pasajero, $reserva) {
            // Bajo lock, igual que ReservaFacturacionController::store(): una
            // factura en paralelo no puede incluir a este pasajero mientras se quita.
            $reservaLocked = Reserva::where('id', $reserva->id)->lockForUpdate()->firstOrFail();

            if ($reservaLocked->pasajeros()->count() <= 1) {
                throw new HttpException(422, 'No se puede quitar: es el último pasajero de la reserva.');
            }

            // Solo cuenta si ESTE pasajero está en una venta (auditoría
            // 2026-09-23): otro pasajero ya facturado no impide quitarlo.
            $yaFacturado = ReservaVenta::pasajeroIdsFacturados($reservaLocked->id)
                ->contains($pasajero->id);

            if ($yaFacturado) {
                throw new HttpException(422, 'No se puede quitar: este pasajero ya fue facturado en una venta de esta reserva.');
            }

            // Las dos tablas tienen FK sin cascadeOnDelete(): se limpian a mano
            // (sin el vuelo, borrar a alguien con vuelo de agencia daba un 500).
            ReservaItemPasajero::where('reserva_pasajero_id', $pasajero->id)->delete();
            ReservaItemVueloPasajero::where('reserva_pasajero_id', $pasajero->id)->delete();
            $pasajero->delete();

            // El cupo del mayorista se contó por pasajero al aceptar: se devuelve uno.
            $opcionElegida = $reservaLocked->alternativa?->opcionMayoristaElegida;
            if ($opcionElegida && $opcionElegida->salida_mayorista_id) {
                SalidaMayorista::where('id', $opcionElegida->salida_mayorista_id)->decrement('cupo_ocupado');
            }
        });
    }
}
