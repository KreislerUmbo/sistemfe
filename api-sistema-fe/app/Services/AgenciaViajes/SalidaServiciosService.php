<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaServicio;

// Servicios a reservar de una salida — fila 13, fase 2b
// (plan-tours-venta-simple-costo-real.md §3.6, D10).
//
// sincronizarDesdeTour() arma la lista desde el Incluye ACTUAL del tour de la
// salida: un servicio por cada ítem que todavía no esté, con el proveedor
// sugerido del tour. Es idempotente: se llama al enganchar cada línea de tour
// y desde el botón "Actualizar desde el tour", y nunca borra ni cambia lo que
// el operador ya asignó (proveedor, cantidad, estado).
//
// El guía no entra en la lista: se asigna en la salida (guía principal) o en
// cada vehículo (D12).
class SalidaServiciosService
{
    /**
     * @return int cuántos servicios se agregaron
     */
    public function sincronizarDesdeTour(SalidaOperativa $salida): int
    {
        if (! $salida->tour_origen_id) {
            return 0;
        }

        $yaEstan = SalidaServicio::where('salida_operativa_id', $salida->id)
            ->whereNotNull('paquete_plantilla_item_id')
            ->pluck('paquete_plantilla_item_id');

        $nuevos = PaquetePlantillaItem::where('paquete_plantilla_id', $salida->tour_origen_id)
            ->whereNull('paquete_plantilla_hijo_id')
            ->whereNull('guia_tarifa_id')
            ->whereNotIn('id', $yaEstan)
            ->with(['destinoServicio.servicio', 'proveedorTarifa.proveedorServicio.destinoServicio.servicio'])
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        foreach ($nuevos as $item) {
            SalidaServicio::create([
                'salida_operativa_id' => $salida->id,
                'paquete_plantilla_item_id' => $item->id,
                'nombre' => TourLineaService::nombreServicio($item),
                // Proveedor sugerido del tour; si no tiene, el de su tarifa.
                'proveedor_id' => $item->proveedor_id ?? $item->proveedorTarifa?->proveedorServicio?->proveedor_id,
                'estado' => SalidaServicio::ESTADO_POR_RESERVAR,
                'orden' => (int) $item->orden,
            ]);
        }

        return $nuevos->count();
    }
}
