<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Exports\ReporteOperativoExport;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\Company;
use App\Services\StorageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Maatwebsite\Excel\Facades\Excel;

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
        return response()->json(['code' => 200] + $this->obtenerFilas($request));
    }

    // Sesión 11d — URL firmada de corta duración para el PDF de solo lectura, mismo
    // patrón que CashSessionController::pdfRangeSignedUrl(): el navegador abre la URL
    // en una pestaña nueva (no lleva el Bearer token), así que la ruta real
    // (self::pdf()) va firmada en vez de detrás de auth:api.
    public function pdfSignedUrl(Request $request)
    {
        $request->validate([
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
        ]);

        $params = array_filter([
            'fecha_desde' => $request->input('fecha_desde'),
            'fecha_hasta' => $request->input('fecha_hasta'),
            'pendiente_asignar' => $request->boolean('pendiente_asignar') ? '1' : null,
            'destino_atractivo_id' => $request->input('destino_atractivo_id'),
            'servicio_id' => $request->input('servicio_id'),
            'tour_id' => $request->input('tour_id'),
            'hotel_proveedor_id' => $request->input('hotel_proveedor_id'),
            // La ruta firmada (self::pdf()) se abre con window.open(), sin header
            // Authorization — auth('api')->user() ahí es null (confirmado leyendo
            // EnsureTokenBelongsToTenant). Acá SÍ hay usuario autenticado: se captura
            // "quién" y "cuándo" en este momento y viaja protegido por la firma, igual
            // que fecha_desde/fecha_hasta.
            'generado_por' => auth('api')->user()->name,
            'generado_en' => now()->toIso8601String(),
        ]);

        $url = URL::temporarySignedRoute('reporte-operativo.pdf', now()->addMinutes(10), $params);

        return response()->json(['url' => $url]);
    }

    public function pdf(Request $request)
    {
        // APP_LOCALE del proyecto es 'en' (config/app.php) — sin esto, translatedFormat()
        // en el blade devuelve nombres de día/mes en inglés ("THURSDAY 27 DE AUGUST") en
        // un documento pensado para repartir impreso al equipo de campo en español.
        Carbon::setLocale('es');

        $reporte = $this->obtenerFilas($request);
        $empresa = Company::first();

        $filasPorFecha = collect($reporte['filas'])->groupBy('fecha');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.agencia-viajes.reporte-operativo', [
            'filasPorFecha' => $filasPorFecha,
            'fechaDesde' => $reporte['fecha_desde'],
            'fechaHasta' => $reporte['fecha_hasta'],
            'empresa' => $empresa,
            // NUNCA StorageUrl::resolve() dentro de un PDF — DomPDF trae
            // enable_remote=false por default, esa URL HTTP no carga (ver
            // StorageUrl::resolveParaPdf(), mismo criterio que SaleController::pdf()/
            // NotaController::pdf()/PaymentReceiptController::pdf()). AlternativaController
            // usa resolve() acá y tiene el mismo bug latente — fuera de alcance de esta
            // sesión, avisado aparte.
            'logoUrl' => StorageUrl::resolveParaPdf($empresa?->logo_horizontal),
            'generadoPor' => $request->input('generado_por', 'Sistema'),
            'generadoEn' => $request->filled('generado_en')
                ? Carbon::parse($request->input('generado_en'))->translatedFormat('d/m/Y H:i')
                : now()->translatedFormat('d/m/Y H:i'),
        ]);
        $pdf->setPaper('a4', 'landscape');

        return $pdf->stream($this->nombreArchivo($reporte['fecha_desde'], $reporte['fecha_hasta'], 'pdf'));
    }

    // Sesión 11d — pedido nuevo del usuario, no estaba en plan-modulo-cotizaciones-
    // reservas.md §8 (que solo pedía PDF). Distinto de pdf(): esta ruta SÍ corre dentro
    // de auth:api (no es firmada, se pide con el Bearer normal), así que auth('api')->user()
    // resuelve directo, sin el problema de "quién generó" que sí afecta a pdf().
    public function export(Request $request)
    {
        $reporte = $this->obtenerFilas($request);

        return Excel::download(
            new ReporteOperativoExport(
                collect($reporte['filas']),
                $reporte['fecha_desde'],
                $reporte['fecha_hasta'],
                auth('api')->user()->name,
                now()
            ),
            $this->nombreArchivo($reporte['fecha_desde'], $reporte['fecha_hasta'], 'xlsx')
        );
    }

    // Sesión 11d — reutilizado por pdf() y export(). "del ... al ..." con guiones (no
    // barras: inválidas en nombre de archivo), pedido explícito del usuario.
    private function nombreArchivo(string $fechaDesde, string $fechaHasta, string $extension): string
    {
        return 'reporte_operativo_del_' . Carbon::parse($fechaDesde)->format('d-m-Y')
            . '_al_' . Carbon::parse($fechaHasta)->format('d-m-Y') . '.' . $extension;
    }

    // Sesión 11d — catálogo de opciones de filtro (destino/servicio/tour/hotel),
    // acotado al MISMO rango de fecha que el reporte pero SIN aplicar esos 4 filtros —
    // así el usuario siempre ve todas las opciones válidas para ese rango, no una lista
    // que se auto-restringe en cascada (simplificación consciente de esta primera
    // versión).
    public function filtrosDisponibles(Request $request)
    {
        [$fechaDesde, $fechaHasta] = $this->resolverRangoFechas($request);

        $items = $this->queryItemsDelRango($fechaDesde, $fechaHasta)->get();

        $destinos = $items
            ->map(fn (ReservaItem $i) => $i->alternativaItem?->proveedorTarifa?->proveedorServicio?->destinoServicio?->destinoAtractivo)
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($d) => ['id' => $d->id, 'nombre' => $d->nombre]);

        $servicios = $items
            ->map(fn (ReservaItem $i) => $i->alternativaItem?->proveedorTarifa?->proveedorServicio?->destinoServicio?->servicio)
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre]);

        $tours = $items
            ->map(fn (ReservaItem $i) => $i->tourOrigen)
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($t) => ['id' => $t->id, 'nombre' => $t->nombre, 'codigo' => $t->codigo]);

        $hoteles = $items
            ->filter(fn (ReservaItem $i) => (bool) $i->alternativaItem?->proveedorTarifa?->tipo_habitacion)
            ->map(fn (ReservaItem $i) => $i->alternativaItem->proveedorTarifa->proveedorServicio?->proveedor)
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->razon_social]);

        return response()->json([
            'code' => 200,
            'destinos' => $destinos->values(),
            'servicios' => $servicios->values(),
            'tours' => $tours->values(),
            'hoteles' => $hoteles->values(),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolverRangoFechas(Request $request): array
    {
        $fechaDesde = $request->filled('fecha_desde')
            ? Carbon::parse($request->input('fecha_desde'))->startOfDay()
            : Carbon::today();
        $fechaHasta = $request->filled('fecha_hasta')
            ? Carbon::parse($request->input('fecha_hasta'))->startOfDay()
            : $fechaDesde->copy();

        return [$fechaDesde, $fechaHasta];
    }

    // Query base compartida entre obtenerFilas() (reporte real, con los 4 filtros de
    // dimensión aplicados) y filtrosDisponibles() (catálogo de opciones, SIN esos
    // filtros) — evita duplicar el whereBetween/whereHas/with entre ambos usos.
    private function queryItemsDelRango(Carbon $fechaDesde, Carbon $fechaHasta): Builder
    {
        return ReservaItem::whereBetween('fecha', [$fechaDesde->toDateString(), $fechaHasta->toDateString()])
            ->whereHas('reserva', fn ($q) => $q->where('estado', '!=', 'cancelada'))
            ->with([
                'reserva.pasajeros',
                'reserva.alternativa.cotizacion',
                'alternativaItem.proveedorTarifa.proveedorServicio.destinoServicio.destinoAtractivo',
                'alternativaItem.proveedorTarifa.proveedorServicio.destinoServicio.servicio',
                'alternativaItem.proveedorTarifa.proveedorServicio.proveedor',
                'alternativaItem.cotizacionPasajeAereo',
                'tourOrigen',
                'guia',
                // Un ítem origen_tipo='guia' puede estar enganchado a una Salida
                // Operativa (tablero de despacho, SalidaOperativaController::
                // attachReservaItem()) — ahí el guía real es el de la salida
                // (compartido entre varias reservas), NUNCA el guia_id propio del
                // ítem. reservas/detalle.vue ya distingue esto (líneas ~290-301: si
                // salida_operativa_id está seteado, muestra la salida de solo lectura
                // en vez del <select>) — el reporte necesita el mismo criterio para no
                // mostrar/permitir un guía duplicado y desincronizado del real.
                'salidaOperativa.guia',
                'pasajeros',
            ]);
    }

    // Los 4 filtros nuevos de la Sesión 11d (destino/servicio/tour/hotel) — todos
    // opcionales, se combinan con AND entre sí (y con fecha/pendiente_asignar, que se
    // aplican aparte en obtenerFilas()).
    private function aplicarFiltrosDimension(Builder $query, Request $request): void
    {
        if ($request->filled('destino_atractivo_id')) {
            $destinoId = $request->integer('destino_atractivo_id');
            $query->whereHas(
                'alternativaItem.proveedorTarifa.proveedorServicio.destinoServicio',
                fn ($q) => $q->where('destino_atractivo_id', $destinoId)
            );
        }

        if ($request->filled('servicio_id')) {
            $servicioId = $request->integer('servicio_id');
            $query->whereHas(
                'alternativaItem.proveedorTarifa.proveedorServicio.destinoServicio',
                fn ($q) => $q->where('servicio_id', $servicioId)
            );
        }

        if ($request->filled('tour_id')) {
            $query->where('tour_origen_id', $request->integer('tour_id'));
        }

        if ($request->filled('hotel_proveedor_id')) {
            $hotelProveedorId = $request->integer('hotel_proveedor_id');
            $query->whereHas('alternativaItem.proveedorTarifa', function ($q) use ($hotelProveedorId) {
                $q->whereNotNull('tipo_habitacion')
                    ->whereHas('proveedorServicio', fn ($q2) => $q2->where('proveedor_id', $hotelProveedorId));
            });
        }
    }

    private function obtenerFilas(Request $request): array
    {
        [$fechaDesde, $fechaHasta] = $this->resolverRangoFechas($request);
        $soloPendienteAsignar = $request->boolean('pendiente_asignar');

        $query = $this->queryItemsDelRango($fechaDesde, $fechaHasta);
        $this->aplicarFiltrosDimension($query, $request);

        $items = $query->orderBy('fecha')->orderBy('hora')->get();

        // Mapa de check-in existente, cargado en una sola query — evita N+1 dentro del
        // loop de abajo. La mayoría de reserva_items no tiene vinculo_especifico, así
        // que este mapa también cubre filas "aplica a todos los pasajeros" (sin fila
        // propia todavía, se resuelve como sin check-in).
        $checkins = ReservaItemPasajero::whereIn('reserva_item_id', $items->pluck('id'))
            ->get()
            ->keyBy(fn (ReservaItemPasajero $r) => $r->reserva_item_id . '_' . $r->reserva_pasajero_id);

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

                $checkin = $checkins->get($item->id . '_' . $pasajero->id);
                $filas[] = $this->armarFila($item, $pasajero, $sinGuia, $vinculoEspecifico, $checkin);
            }
        }

        return [
            'fecha_desde' => $fechaDesde->toDateString(),
            'fecha_hasta' => $fechaHasta->toDateString(),
            'total_items' => count($filas),
            'total_sin_guia' => $totalSinGuia,
            'filas' => $filas,
        ];
    }

    // Mismo criterio que reservas/detalle.vue::tieneAsignacionAplicable() — solo
    // 'proveedor'/'guia' tienen un campo de asignación operativa real hoy.
    // 'incluyendo es_referencial' (fila 11e): un guía/proveedor referencial es un
    // placeholder, no una asignación confirmada — cuenta igual como pendiente.
    private function itemSinAsignacionOperativa(ReservaItem $item): bool
    {
        $origenTipo = $item->alternativaItem?->origen_tipo;

        if ($origenTipo === AlternativaItem::ORIGEN_GUIA) {
            $guia = $this->resolverGuiaEfectivo($item);

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

    // Un ítem origen_tipo='guia' enganchado a una Salida Operativa (tablero de
    // despacho) tiene su guía real ahí, compartido entre varias reservas — el
    // guia_id propio del ítem queda huérfano/sin usar en ese caso (mismo criterio ya
    // establecido en reservas/detalle.vue). Sin esto, el reporte mostraría "sin
    // asignar" con un guía real ya puesto en la salida, o peor: dejaría "corregirlo"
    // acá creando un segundo guía desincronizado del de la salida.
    private function resolverGuiaEfectivo(ReservaItem $item)
    {
        return $item->salida_operativa_id ? $item->salidaOperativa?->guia : $item->guia;
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

    private function armarFila(ReservaItem $item, ReservaPasajero $pasajero, bool $sinGuia, bool $vinculoEspecifico, ?ReservaItemPasajero $checkin): array
    {
        $alternativaItem = $item->alternativaItem;
        $proveedorTarifa = $alternativaItem?->proveedorTarifa;
        $destinoServicio = $proveedorTarifa?->proveedorServicio?->destinoServicio;

        return [
            'reserva_id' => $item->reserva_id,
            'reserva_item_id' => $item->id,
            'checkin_realizado' => (bool) $checkin?->checkin_realizado,
            'checkin_hora' => optional($checkin?->checkin_hora)->toDateTimeString(),
            // Sesión 11d — el frontend lo necesita para decidir qué acción de
            // reasignación mostrar inline: 'guia' resuelve con el select de guías
            // (mismo patrón que reservas/detalle.vue); 'proveedor' sin asignar no
            // tiene equivalente simple acá (el buscador de proveedor_tarifa por
            // destino es propio de detalle.vue) — el reporte enlaza a la reserva.
            'origen_tipo' => $alternativaItem?->origen_tipo,
            // Módulo 12 (códigos y numeración, revisión 26-ago-2026):
            // código propio de la reserva, con fallback al de la cotización
            // para reservas creadas antes de activar el módulo.
            'codigo_reserva' => $item->reserva->codigo ?? $item->reserva->alternativa?->cotizacion?->codigo,
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
            'servicio_id' => $destinoServicio?->servicio_id,
            'destino' => $destinoServicio?->destinoAtractivo?->nombre,
            // Sesión 11d (mejora post-11d) — nombre del proveedor asignado a CUALQUIER
            // ítem origen_tipo='proveedor' (antes solo se exponía para hoteles vía
            // 'hotel'). El reporte necesita mostrarlo para poder reasignarlo inline —
            // mismo criterio que ReservaItemController::update() ya permite, solo que
            // el reporte no tenía forma de mostrar "a quién" hasta ahora.
            'proveedor' => $proveedorTarifa?->proveedorServicio?->proveedor?->razon_social,
            'hotel' => $proveedorTarifa?->tipo_habitacion
                ? ($proveedorTarifa->proveedorServicio?->proveedor?->razon_social ?? 'Hotel')
                : null,
            'fecha' => optional($item->fecha)->toDateString(),
            'hora' => $item->hora,
            'guia' => ($guiaEfectivo = $this->resolverGuiaEfectivo($item)) ? [
                'id' => $guiaEfectivo->id,
                'nombre' => $guiaEfectivo->nombre,
                'es_referencial' => (bool) $guiaEfectivo->es_referencial,
            ] : null,
            'sin_guia' => $sinGuia,
            'vinculo_especifico' => $vinculoEspecifico,
            // El frontend necesita esto para bloquear la edición directa de guía: si
            // el ítem está enganchado a una Salida Operativa, el guía se cambia desde
            // ahí (compartido con otras reservas), no acá — ver resolverGuiaEfectivo().
            'salida_operativa_id' => $item->salida_operativa_id,
            'salida_vehiculo' => $item->salida_operativa_id ? $item->salidaOperativa?->vehiculo_descripcion : null,
        ];
    }
}
