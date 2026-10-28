<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;
use App\Models\AgenciaViajes\OpcionMayoristaTour;

/**
 * T-025 (H2) — la cotización a la que pertenece cualquier cosa del cotizador,
 * para preguntarle a CotizacionPolicy si el usuario es su vendedor (o ve
 * todas). Un solo lugar, en vez de repetir la cadena en cada método.
 *
 * null = no cuelga de ninguna cotización todavía (un hotel no registrado
 * recién creado, que ningún servicio usa): no hay dueño que revisar.
 */
class CotizacionDuena
{
    public static function de(mixed $recurso): ?Cotizacion
    {
        return match (true) {
            $recurso instanceof Cotizacion => $recurso,
            $recurso instanceof Alternativa => $recurso->cotizacion,
            $recurso instanceof AlternativaItem, $recurso instanceof AlternativaDestino => self::de($recurso->alternativa),
            $recurso instanceof OpcionMayorista => self::de($recurso->alternativa ?? $recurso->alternativaDestino?->alternativa),
            $recurso instanceof OpcionMayoristaOpcional, $recurso instanceof OpcionMayoristaTour => self::de($recurso->opcionMayorista),
            $recurso instanceof OpcionHotelTarifa => self::de($recurso->opcionHotel),
            $recurso instanceof OpcionHotel => self::deHotel($recurso),
            default => null,
        };
    }

    // Un hotel de mayorista cuelga de su opción; uno no registrado (local, sin
    // opción) se reconoce por el servicio que usa alguna de sus tarifas.
    private static function deHotel(OpcionHotel $hotel): ?Cotizacion
    {
        if ($hotel->opcion_mayorista_id) {
            return self::de($hotel->opcionMayorista);
        }

        $item = AlternativaItem::whereIn('opcion_hotel_tarifa_id', $hotel->opcionesHotelTarifas()->select('id'))->first();

        return $item ? self::de($item) : null;
    }
}
