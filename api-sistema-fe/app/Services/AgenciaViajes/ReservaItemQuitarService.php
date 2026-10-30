<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaItemVueloPasajero;
use App\Models\AgenciaViajes\SalidaOperativa;

// Borra un servicio de la reserva con su limpieza. Salió de ReservaItemController::destroy() en
// T-005 sin cambiar el comportamiento, para que "aplicar una modificación" quite igual los
// servicios que ya no están. NO revisa nada (último ítem, ya facturado, reserva activa) ni abre
// transacción ni toma el lock de la reserva: eso lo hace quien llama, bajo su lock.
class ReservaItemQuitarService
{
    public function quitar(ReservaItem $item): void
    {
        // reserva_item_pasajero.reserva_item_id es FK sin
        // cascadeOnDelete() — hay que limpiarla a mano antes de
        // poder borrar el ítem.
        ReservaItemPasajero::where('reserva_item_id', $item->id)->delete();
        // Bug real (auditoría 2026-09-22): reserva_item_vuelo_pasajero
        // (migración 2026_08_27_110000) tampoco tiene cascadeOnDelete()
        // y quedó fuera de este limpiado desde el día que se creó —
        // borrar un ítem con vuelo de agencia ya cargado tiraba un 500
        // de violación de FK en vez de un mensaje claro.
        ReservaItemVueloPasajero::where('reserva_item_id', $item->id)->delete();
        $salidaId = $item->salida_operativa_id;
        $item->delete();
        // Bug real (auditoría 2026-09-22): ver
        // SalidaOperativa::eliminarSiQuedoVacia() — borrar el
        // último ítem enganchado a una salida la dejaba fantasma en
        // el tablero, con 0 pasajeros. Una salida compartida con otra
        // reserva NUNCA se borra: eliminarSiQuedoVacia() decide.
        SalidaOperativa::eliminarSiQuedoVacia($salidaId);
    }
}
