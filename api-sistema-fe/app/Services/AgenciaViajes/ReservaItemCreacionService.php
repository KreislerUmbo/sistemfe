<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;

// Crea el `reserva_item` de un servicio de una alternativa. Salió de
// ReservaController::crearReservaItemDesdeAlternativaItem() en T-005 sin cambiar su comportamiento,
// para que lo usen igual aceptar (crearReservaDesdeAlternativa), sincronizarItems y "aplicar una
// modificación" (ModificacionReservaService). No abre transacción: el que llama ya está en una.
class ReservaItemCreacionService
{
    // Extraído de crearReservaDesdeAlternativa() para reusar en
    // sincronizarItems() (agregar un servicio a la cotización DESPUÉS de que
    // la reserva ya está activa nunca se refleja solo — el staff dispara la
    // sincronización a mano, ver sincronizarItems()).
    public function crear(
        Reserva $reserva,
        AlternativaItem $alternativaItem,
        $fechaViajeDesde,
        array $mapaPasajeros
    ): ReservaItem {
        $fechaCalculada = ($fechaViajeDesde && $alternativaItem->dia_referencial)
            ? $fechaViajeDesde->copy()->addDays($alternativaItem->dia_referencial - 1)
            : null;

        $reservaItem = ReservaItem::create([
            'reserva_id' => $reserva->id,
            'alternativa_item_id' => $alternativaItem->id,
            'proveedor_tarifa_id' => $alternativaItem->proveedor_tarifa_id,
            // Sesión M2 — misma copia 1:1 que proveedor_tarifa_id, para no
            // perder qué fila de la matriz hotel×habitación fijó el precio
            // cuando el ítem no tiene ProveedorTarifa real.
            'opcion_hotel_tarifa_id' => $alternativaItem->opcion_hotel_tarifa_id,
            // Sesión 12h — quién opera el destino internacional de este
            // ítem, copiado 1:1 igual que proveedor_tarifa_id. Reasignable
            // después SOLO vía reasignarMayorista() (nunca update()
            // genérico), ver docblock de ReservaItem::opcionMayorista().
            'opcion_mayorista_id' => $alternativaItem->opcion_mayorista_id,
            'fecha' => $fechaCalculada,
            // Fase 1 del fix Cotización↔Reserva — este método es el único
            // punto de creación de reserva_items (aceptar y sincronizar),
            // así que todo lo que nace acá es 'auto' por definición. Un
            // ítem solo pasa a 'manual' después, vía
            // ReservaItemController::update().
            'fecha_origen' => ReservaItem::FECHA_ORIGEN_AUTO,
            // Sesión 11b4: propaga de qué tour vino el ítem (si vino de
            // explotar un paquete_combo) para que la agrupación visual
            // "Día 1/Día 2" sobreviva también en la reserva.
            'tour_origen_id' => $alternativaItem->tour_origen_id,
            // Análisis de impuestos (28-ago-2026): propaga el tratamiento
            // tributario ya resuelto en la cotización — es lo único que
            // ReservaFacturacionController puede usar sin volver a navegar
            // proveedorTarifa (necesario para los orígenes manual/
            // mayorista/guia/pasaje_aereo, que no tienen proveedor_tarifa).
            'tip_afe_igv' => $alternativaItem->tip_afe_igv,
            'destino_tributario' => $alternativaItem->destino_tributario,
            // Fila 13, fase 1b (§3.9b, N14): la carga de tour (para la mezcla
            // de IGV de la 1c y la salida de la fase 2) y la copia del costo
            // por servicio, congelada al aceptar.
            'carga_tour' => $alternativaItem->carga_tour,
            'carga_combo' => $alternativaItem->carga_combo,
            'desglose_costo_snapshot' => $alternativaItem->desglose_costo_snapshot,
        ]);

        // Proveedor compartido y, desde la fase 2a, la línea de tour: ver
        // SalidaEngancheService.
        app(SalidaEngancheService::class)->enganchar($reservaItem, $alternativaItem, $fechaCalculada);

        // pax_incluidos null/vacío = aplica a todos, no se crea ninguna
        // fila (mismo criterio que pax_incluidos en alternativa_items).
        if (! empty($alternativaItem->pax_incluidos)) {
            foreach ($alternativaItem->pax_incluidos as $cotizacionPasajeroId) {
                if (isset($mapaPasajeros[$cotizacionPasajeroId])) {
                    ReservaItemPasajero::create([
                        'reserva_item_id' => $reservaItem->id,
                        'reserva_pasajero_id' => $mapaPasajeros[$cotizacionPasajeroId],
                    ]);
                }
            }
        }

        return $reservaItem;
    }
}
