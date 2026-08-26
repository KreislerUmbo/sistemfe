<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaPasajero;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// Sesión 11e (plan-hoja-de-ruta-ejecucion.md) — plan-modulo-cotizaciones-reservas.md §8.
// Reemplaza la lógica hoy embebida en reservas/detalle.vue
// (tieneAsignacionAplicable()/itemsAsignables/itemsSinAsignar) por un endpoint real,
// agregado por fecha/rango en vez de por reserva individual.
//
// Desviación confirmada del spec original (ver plan aprobado de esta sesión): §8 asume
// que cada reserva_item tiene sus pasajeros vinculados vía reserva_item_pasajero — pero
// esa tabla está vacía en la inmensa mayoría de reservas reales (confirmado en Sesión 11v
// contra agencia-demo). Cuando un ítem no tiene ningún vínculo específico, se trata como
// aplicable a TODOS los pasajeros de la reserva (así funciona en la práctica: un tour
// grupal no se reparte por persona) — ver resolverPasajerosDelItem().
class ReporteOperativoController extends Controller
{
    public function index(Request $request)
    {
        $fechaDesde = $request->filled('fecha_desde')
            ? Carbon::parse($request->input('fecha_desde'))->startOfDay()
            : Carbon::today();
        $fechaHasta = $request->filled('fecha_hasta')
            ? Carbon::parse($request->input('fecha_hasta'))->startOfDay()
            : $fechaDesde->copy();
        $soloPendienteAsignar = $request->boolean('pendiente_asignar');

        $items = ReservaItem::whereBetween('fecha', [$fechaDesde->toDateString(), $fechaHasta->toDateString()])
            ->whereHas('reserva', fn ($q) => $q->where('estado', '!=', 'cancelada'))
            ->with([
                'reserva.pasajeros',
                'reserva.alternativa.cotizacion',
                'alternativaItem.proveedorTarifa.proveedorServicio.destinoServicio.destinoAtractivo',
                'alternativaItem.proveedorTarifa.proveedorServicio.proveedor',
                'alternativaItem.cotizacionPasajeAereo',
                'guia',
                'pasajeros',
            ])
            ->orderBy('fecha')
            ->orderBy('hora')
            ->get();

        $filas = [];
        $totalSinGuia = 0;

        foreach ($items as $item) {
            $sinGuia = $this->itemSinAsignacionOperativa($item);
            if ($soloPendienteAsignar && ! $sinGuia) {
                continue;
            }

            $pasajeros = $this->resolverPasajerosDelItem($item);
            $vinculoEspecifico = $item->pasajeros->isNotEmpty();

            foreach ($pasajeros as $pasajero) {
                if ($sinGuia) {
                    $totalSinGuia++;
                }

                $filas[] = $this->armarFila($item, $pasajero, $sinGuia, $vinculoEspecifico);
            }
        }

        return response()->json([
            'code' => 200,
            'fecha_desde' => $fechaDesde->toDateString(),
            'fecha_hasta' => $fechaHasta->toDateString(),
            'total_items' => count($filas),
            'total_sin_guia' => $totalSinGuia,
            'filas' => $filas,
        ]);
    }

    // Mismo criterio que reservas/detalle.vue::tieneAsignacionAplicable() — solo
    // 'proveedor'/'guia' tienen un campo de asignación operativa real hoy.
    // 'incluyendo es_referencial' (fila 11e): un guía/proveedor referencial es un
    // placeholder, no una asignación confirmada — cuenta igual como pendiente.
    private function itemSinAsignacionOperativa(ReservaItem $item): bool
    {
        $origenTipo = $item->alternativaItem?->origen_tipo;

        if ($origenTipo === AlternativaItem::ORIGEN_GUIA) {
            return ! $item->guia_id || (bool) $item->guia?->es_referencial;
        }

        if ($origenTipo === AlternativaItem::ORIGEN_PROVEEDOR) {
            if (! $item->proveedor_tarifa_id) {
                return true;
            }

            return (bool) $item->alternativaItem?->proveedorTarifa?->proveedorServicio?->proveedor?->es_referencial;
        }

        return false;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ReservaPasajero>
     */
    private function resolverPasajerosDelItem(ReservaItem $item)
    {
        if ($item->pasajeros->isNotEmpty()) {
            return $item->pasajeros;
        }

        return $item->reserva->pasajeros;
    }

    private function armarFila(ReservaItem $item, ReservaPasajero $pasajero, bool $sinGuia, bool $vinculoEspecifico): array
    {
        $alternativaItem = $item->alternativaItem;
        $proveedorTarifa = $alternativaItem?->proveedorTarifa;
        $destinoServicio = $proveedorTarifa?->proveedorServicio?->destinoServicio;

        return [
            'reserva_id' => $item->reserva_id,
            'reserva_item_id' => $item->id,
            'codigo_reserva' => $item->reserva->alternativa?->cotizacion?->codigo,
            'pasajero' => [
                'id' => $pasajero->id,
                'nombre' => $pasajero->nombre,
                'documento' => $pasajero->documento,
                'tipo_pax' => $pasajero->tipo_pax,
                'alimentacion_especial' => $pasajero->alimentacion_especial,
                'discapacidad' => $pasajero->discapacidad,
            ],
            'vuelo_ida' => $pasajero->vuelo_aerolinea_ida ? [
                'aerolinea' => $pasajero->vuelo_aerolinea_ida,
                'fecha' => optional($pasajero->vuelo_fecha_ida)->toDateString(),
                'hora' => $pasajero->vuelo_hora_ida,
            ] : null,
            'vuelo_vuelta' => $pasajero->vuelo_aerolinea_vuelta ? [
                'aerolinea' => $pasajero->vuelo_aerolinea_vuelta,
                'fecha' => optional($pasajero->vuelo_fecha_vuelta)->toDateString(),
                'hora' => $pasajero->vuelo_hora_vuelta,
            ] : null,
            'servicio' => $alternativaItem ? ReservaController::resolverNombreItem($alternativaItem) : 'Servicio',
            'destino' => $destinoServicio?->destinoAtractivo?->nombre,
            'hotel' => $proveedorTarifa?->tipo_habitacion
                ? ($proveedorTarifa->proveedorServicio?->proveedor?->razon_social ?? 'Hotel')
                : null,
            'fecha' => optional($item->fecha)->toDateString(),
            'hora' => $item->hora,
            'guia' => $item->guia ? [
                'id' => $item->guia->id,
                'nombre' => $item->guia->nombre,
                'es_referencial' => (bool) $item->guia->es_referencial,
            ] : null,
            'sin_guia' => $sinGuia,
            'vinculo_especifico' => $vinculoEspecifico,
        ];
    }
}
