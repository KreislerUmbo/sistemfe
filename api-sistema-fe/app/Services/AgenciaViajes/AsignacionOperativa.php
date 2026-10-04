<?php

declare(strict_types=1);

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\ReservaItem;
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
        return $item->salida_operativa_id ? $item->salidaOperativa?->guia : $item->guia;
    }

    /**
     * Sin asignación: guía o proveedor ausente, o asignado a uno "referencial" (placeholder de
     * cotización). Requiere cargadas alternativaItem.proveedorTarifa.proveedorServicio.proveedor,
     * guia y salidaOperativa.guia.
     */
    public static function sinAsignar(ReservaItem $item): bool
    {
        $origenTipo = $item->alternativaItem?->origen_tipo;

        if ($origenTipo === AlternativaItem::ORIGEN_GUIA) {
            $guia = self::guiaEfectivo($item);

            return ! $guia || (bool) $guia->es_referencial;
        }

        if ($origenTipo === AlternativaItem::ORIGEN_PROVEEDOR) {
            if (! $item->proveedor_tarifa_id) {
                return true;
            }

            return (bool) $item->alternativaItem?->proveedorTarifa?->proveedorServicio?->proveedor?->es_referencial;
        }

        return false;
    }
}
