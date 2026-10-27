<?php

declare(strict_types=1);

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use Illuminate\Database\Eloquent\Builder;

/**
 * Criterio operativo de los servicios de una reserva, compartido por el Reporte Operativo y el
 * Inicio (Dashboard): qué ítems son servicios reales del rango y cuáles siguen sin guía o
 * proveedor asignado. Extraído de ReporteOperativoController sin cambiar el criterio.
 */
class AsignacionOperativa
{
    /**
     * Ítems de reservas no canceladas con fecha en el rango, sin el "Ajuste de redondeo" (ítem
     * manual de precio, no un servicio operativo; pedido del usuario en el rediseño del reporte).
     *
     * @return Builder<ReservaItem>
     */
    public static function itemsDelRango(string $desde, string $hasta): Builder
    {
        return ReservaItem::whereBetween('fecha', [$desde, $hasta])
            ->whereHas('reserva', fn ($q) => $q->where('estado', '!=', 'cancelada'))
            ->whereDoesntHave('alternativaItem', fn ($q) => $q
                ->where('origen_tipo', AlternativaItem::ORIGEN_MANUAL)
                ->where('descripcion_manual', 'Ajuste de redondeo'));
    }

    /**
     * Un ítem de guía enganchado a una Salida Operativa tiene su guía real en la salida
     * (compartido entre reservas); el guia_id propio del ítem queda sin usar en ese caso.
     */
    public static function guiaEfectivo(ReservaItem $item): ?Guia
    {
        if (! $item->salida_operativa_id) {
            return $item->guia;
        }

        // Fila 13, fase 2b (D12): si la reserva va en un vehículo con su
        // propio guía (grupo grande), ese es su guía; si no, el de la salida.
        $salida = $item->salidaOperativa;

        return $salida?->vehiculoDeReserva((int) $item->reserva_id)?->guia ?? $salida?->guia;
    }

    /**
     * Sin asignación: guía o proveedor ausente, o asignado a uno "referencial" (placeholder de
     * cotización). Requiere cargadas alternativaItem, proveedorTarifa.proveedorServicio.proveedor
     * (la del ítem de la RESERVA), guia y salidaOperativa.guia.
     */
    public static function sinAsignar(ReservaItem $item): bool
    {
        $origenTipo = $item->alternativaItem?->origen_tipo;

        // Fila 13, fase 2a: la línea de tour no tiene guía ni proveedor
        // propios; se opera por su salida. Sin asignar = no está en ninguna
        // salida (o la suya se canceló); el tour lleva guía en su Incluye y
        // ni la salida ni el vehículo de la reserva tienen guía; o algún
        // servicio a reservar de la salida no tiene proveedor (fase 2b). Que
        // falte un vehículo es un aviso del tablero, no un pendiente: hay
        // tours sin transporte.
        if ($origenTipo === AlternativaItem::ORIGEN_TOUR) {
            $salida = $item->salidaOperativa;
            if (! $salida || $salida->estado === SalidaOperativa::ESTADO_CANCELADA) {
                return true;
            }

            if (! self::guiaEfectivo($item) && self::tourLlevaGuia($item->tour_origen_id)) {
                return true;
            }

            return $salida->servicios->contains(fn ($servicio) => $servicio->proveedor_id === null);
        }

        if ($origenTipo === AlternativaItem::ORIGEN_GUIA) {
            $guia = self::guiaEfectivo($item);

            return ! $guia || (bool) $guia->es_referencial;
        }

        // T-054: el proveedor real es el del ítem de la reserva (lo cambian
        // reasignar proveedor y reasignar hotel), no el cotizado. Un hotel
        // reasignado a uno no registrado (opcion_hotel_tarifa_id) sí está
        // asignado aunque no tenga tarifa de catálogo.
        if ($origenTipo === AlternativaItem::ORIGEN_PROVEEDOR) {
            if (! $item->proveedor_tarifa_id) {
                return ! $item->opcion_hotel_tarifa_id;
            }

            return (bool) $item->proveedorTarifa?->proveedorServicio?->proveedor?->es_referencial;
        }

        return false;
    }

    /** @var array<int, bool> tour_id => lleva guía (cache por petición) */
    private static array $tourLlevaGuia = [];

    /**
     * Fila 13, fase 2a: un tour "lleva guía" si su Incluye tiene un servicio de guía.
     */
    public static function tourLlevaGuia(?int $tourId): bool
    {
        if ($tourId === null) {
            return false;
        }

        return self::$tourLlevaGuia[$tourId] ??= PaquetePlantillaItem::where('paquete_plantilla_id', $tourId)
            ->whereNull('paquete_plantilla_hijo_id')
            ->whereNotNull('guia_tarifa_id')
            ->exists();
    }
}
