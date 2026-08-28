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
use Illuminate\Support\Str;
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

        $dias = $this->armarVistaAgrupada(
            $reporte['filas'],
            Carbon::parse($reporte['fecha_desde']),
            Carbon::parse($reporte['fecha_hasta'])
        );

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.agencia-viajes.reporte-operativo', [
            'dias' => $dias,
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
        // Mismo motivo que pdf(): APP_LOCALE='en' (config/app.php) haría que los
        // encabezados de día de la hoja "Vista operativa" salieran en inglés.
        Carbon::setLocale('es');

        $reporte = $this->obtenerFilas($request);

        $dias = $this->armarVistaAgrupada(
            $reporte['filas'],
            Carbon::parse($reporte['fecha_desde']),
            Carbon::parse($reporte['fecha_hasta'])
        );

        return Excel::download(
            new ReporteOperativoExport(
                collect($reporte['filas']),
                $dias,
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
            // Pedido explícito del usuario (rediseño-reporte-operativo): "Ajuste de
            // redondeo" es un AlternativaItem manual puramente de PRECIO (costo=0,
            // sin destino/guía/proveedor real — ver AlternativaItemController::
            // store(), línea ~378) que se genera automáticamente cuando el paquete
            // tiene ajuste_redondeo configurado. No es un servicio operativo real,
            // así que no pertenece al Reporte Operativo (ni pantalla en vivo, ni
            // PDF, ni Excel) — sí debe seguir viéndose en Reservas/cotización, donde
            // es información de precio legítima. Filtrado acá en la query base
            // porque la comparten obtenerFilas() (index/pdf/export) y
            // filtrosDisponibles() (catálogo de filtros) — así tampoco aparece como
            // opción de filtro.
            ->whereDoesntHave('alternativaItem', fn ($q) => $q
                ->where('origen_tipo', AlternativaItem::ORIGEN_MANUAL)
                ->where('descripcion_manual', 'Ajuste de redondeo'))
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
                // Vuelo vendido por la AGENCIA (corrección 2026-08-27, tabla
                // propia — ver docblock de ReservaItemVueloPasajero, sin
                // relación con 'pasajeros' de arriba).
                'vueloPasajeros',
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

    // Pedido explícito del usuario (rediseño-reporte-operativo): mostrar el nombre
    // COMERCIAL del proveedor en vez de la razón social — es el nombre por el que
    // el equipo de campo reconoce el lugar. nombre_comercial es más nuevo que
    // razon_social (no todos los proveedores lo tienen cargado todavía), así que
    // cae a razon_social cuando está vacío en vez de mostrar un dato en blanco.
    private function nombreProveedor(?\App\Models\AgenciaViajes\Proveedor $proveedor): ?string
    {
        if (! $proveedor) {
            return null;
        }

        return $proveedor->nombre_comercial ?: $proveedor->razon_social;
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
            // Vuelo por CUENTA PROPIA del pasajero (informativo, sin relación
            // con ningún reserva_item real — se pega igual a cualquier fila
            // de este pasajero, sea hotel/tour/lo que sea, mismo criterio de
            // siempre). Distinto de vuelo_agencia_ida/vuelta más abajo.
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
            // Vuelo vendido por la AGENCIA — tabla propia
            // (ReservaItemVueloPasajero, corrección 2026-08-27 tras un bug
            // real: compartir fila con reserva_item_pasajero hacía que el
            // checkbox de Asignación pudiera borrar el vuelo ya cargado).
            // Solo se llena en la fila del ítem que efectivamente ES el
            // pasaje aéreo cotizado, nunca se mezcla con vuelo_ida/vuelta de
            // arriba. $item->vueloPasajeros ya viene eager-loaded desde
            // queryItemsDelRango(), sin query nueva acá.
            'vuelo_agencia_ida' => ($vueloAgencia = $item->vueloPasajeros->firstWhere('reserva_pasajero_id', $pasajero->id))
                && $vueloAgencia->vuelo_numero_ida ? [
                'numero' => $vueloAgencia->vuelo_numero_ida,
                'aerolinea' => $vueloAgencia->vuelo_aerolinea_confirmada ?? $alternativaItem?->cotizacionPasajeAereo?->aerolinea,
                'fecha' => $vueloAgencia->vuelo_fecha_ida,
                'hora' => $vueloAgencia->vuelo_hora_ida,
            ] : null,
            'vuelo_agencia_vuelta' => $vueloAgencia && $vueloAgencia->vuelo_numero_vuelta ? [
                'numero' => $vueloAgencia->vuelo_numero_vuelta,
                'aerolinea' => $vueloAgencia->vuelo_aerolinea_confirmada ?? $alternativaItem?->cotizacionPasajeAereo?->aerolinea,
                'fecha' => $vueloAgencia->vuelo_fecha_vuelta,
                'hora' => $vueloAgencia->vuelo_hora_vuelta,
            ] : null,
            'servicio' => $alternativaItem ? ReservaController::resolverNombreItem($alternativaItem) : 'Servicio',
            'servicio_id' => $destinoServicio?->servicio_id,
            // Categoría amplia del servicio (ej. "Transporte", "Entrada"), a
            // diferencia de 'servicio' de arriba que es el nombre completo
            // del ítem — usada para sub-agrupar "Servicios sueltos" en la
            // vista jerárquica de armarVistaAgrupada().
            'servicio_nombre' => $destinoServicio?->servicio?->nombre,
            // tourOrigen ya viene eager-loaded desde queryItemsDelRango()
            // (relación propia de ReservaItem, no de alternativaItem) —
            // usado para agrupar por Tour en la vista jerárquica.
            'tour_origen_id' => $item->tour_origen_id,
            'tour_origen_nombre' => $item->tourOrigen?->nombre,
            'destino' => $destinoServicio?->destinoAtractivo?->nombre,
            // Sesión 11d (mejora post-11d) — nombre del proveedor asignado a CUALQUIER
            // ítem origen_tipo='proveedor' (antes solo se exponía para hoteles vía
            // 'hotel'). El reporte necesita mostrarlo para poder reasignarlo inline —
            // mismo criterio que ReservaItemController::update() ya permite, solo que
            // el reporte no tenía forma de mostrar "a quién" hasta ahora.
            'proveedor' => $this->nombreProveedor($proveedorTarifa?->proveedorServicio?->proveedor),
            'hotel' => $proveedorTarifa?->tipo_habitacion
                ? ($this->nombreProveedor($proveedorTarifa->proveedorServicio?->proveedor) ?? 'Hotel')
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

    // Sesión rediseño-reporte-operativo — capa nueva usada SOLO por pdf()/export(),
    // nunca por index() (esa sigue devolviendo la lista plana tal cual, para no
    // romper el contrato que ya consume reporte-operativo/index.vue). Reestructura
    // $filas (fecha → grupo → pasajero → sus filas) para el layout jerárquico que
    // pidió el usuario: Día → Tour (o "Servicios sueltos · categoría") → Pasajero
    // con sus datos combinados → cada servicio como sub-fila.
    //
    // @param array<int, array> $filas del mismo shape que devuelve obtenerFilas()['filas']
    // @return array<int, array{fecha: string, grupos: array}>
    private function armarVistaAgrupada(array $filas, Carbon $fechaDesde, Carbon $fechaHasta): array
    {
        $hotelDelDia = $this->hotelDelDiaPorPasajero($filas);
        $filasExtendidas = $this->extenderConFilasDeVuelo($filas, $fechaDesde, $fechaHasta);

        $porFecha = [];

        foreach ($filasExtendidas as $fila) {
            $fecha = $fila['fecha'];
            $pasajeroId = $fila['pasajero']['id'];
            [$grupoKey, $grupoNombre, $esTour] = $this->resolverGrupo($fila);

            if (! isset($porFecha[$fecha])) {
                $porFecha[$fecha] = ['fecha' => $fecha, 'grupos' => []];
            }

            if (! isset($porFecha[$fecha]['grupos'][$grupoKey])) {
                $porFecha[$fecha]['grupos'][$grupoKey] = [
                    'nombre' => $grupoNombre,
                    'es_tour' => $esTour,
                    'guia' => null,
                    'vehiculo' => null,
                    // Candidatos internos para resolverGuiaDeGrupo() más abajo —
                    // se descartan antes de devolver la estructura final.
                    '_guia_via_salida' => null,
                    '_vehiculo_via_salida' => null,
                    '_guia_directo' => null,
                    'pasajeros' => [],
                ];
            }

            $grupo = &$porFecha[$fecha]['grupos'][$grupoKey];

            // Guía del GRUPO (solo Tours): preferimos la de una Salida Operativa
            // compartida (dato confirmado/compartido entre reservas) y solo si
            // ninguna existe caemos al guia_id asignado directo al ítem — mismo
            // fallback que ya usa resolverGuiaEfectivo() por ítem individual, para
            // no "perder" una asignación real solo porque no pasó por el tablero
            // de despacho.
            if ($esTour && $fila['guia']) {
                if (! empty($fila['salida_operativa_id']) && $grupo['_guia_via_salida'] === null) {
                    $grupo['_guia_via_salida'] = $fila['guia'];
                    $grupo['_vehiculo_via_salida'] = $fila['salida_vehiculo'];
                } elseif ($grupo['_guia_directo'] === null) {
                    $grupo['_guia_directo'] = $fila['guia'];
                }
            }

            if (! isset($grupo['pasajeros'][$pasajeroId])) {
                $nombre = trim((string) ($fila['pasajero']['nombre'] ?? ''));

                $grupo['pasajeros'][$pasajeroId] = [
                    'pasajero' => $fila['pasajero'] + [
                        'hotel_del_dia' => $grupoKey === self::GRUPO_ALOJAMIENTO
                            ? null
                            : ($hotelDelDia[$fecha . '_' . $pasajeroId]['texto'] ?? null),
                        // Bloque combinado en blanco (celdas de rowspan/merge sin
                        // ningún texto) es mucho más confuso en el layout jerárquico
                        // que en la tabla plana de antes — el pasajero "shell" sin
                        // nombre completado (ReservaPasajero con nombre/documento
                        // vacío, ver docblock del modelo) ya existía antes, pero acá
                        // se nota mucho más. Fallback visible con el número de
                        // reserva para que quede claro qué completar en Reservas.
                        'nombre_display' => $nombre !== '' ? $nombre : "Pasajero sin datos (reserva #{$fila['reserva_id']})",
                    ],
                    'filas' => [],
                ];
            }

            $grupo['pasajeros'][$pasajeroId]['filas'][] = [
                'hora' => $fila['hora'],
                'servicio' => $fila['servicio'],
                'destino' => $fila['destino'],
                // Guía por fila solo tiene sentido en "Servicios sueltos" (sin
                // escolta de grupo) — en un Tour ya se muestra una vez en el
                // encabezado del grupo, mostrarla también por fila sería
                // redundante.
                'guia' => $esTour ? null : $fila['guia'],
                'sin_guia' => $esTour ? false : $fila['sin_guia'],
                'checkin' => $fila['checkin_realizado'],
                'reserva_id' => $fila['reserva_id'],
            ];

            unset($grupo);
        }

        $this->suprimirAlojamientoDuplicado($porFecha, $hotelDelDia);

        ksort($porFecha);

        foreach ($porFecha as &$dia) {
            foreach ($dia['grupos'] as &$grupo) {
                if ($grupo['es_tour']) {
                    $grupo['guia'] = $grupo['_guia_via_salida'] ?? $grupo['_guia_directo'];
                    $grupo['vehiculo'] = $grupo['_guia_via_salida'] !== null ? $grupo['_vehiculo_via_salida'] : null;
                }
                unset($grupo['_guia_via_salida'], $grupo['_vehiculo_via_salida'], $grupo['_guia_directo']);

                usort($grupo['pasajeros'], fn (array $a, array $b) => strcmp(
                    $a['pasajero']['nombre'] ?? '',
                    $b['pasajero']['nombre'] ?? ''
                ));

                foreach ($grupo['pasajeros'] as &$pax) {
                    usort($pax['filas'], fn (array $a, array $b) => strcmp($a['hora'] ?? '', $b['hora'] ?? ''));
                }
                unset($pax);
            }
            unset($grupo);
            $dia['grupos'] = array_values($dia['grupos']);
        }
        unset($dia);

        return array_values($porFecha);
    }

    // Pedido explícito del usuario, sesión rediseño-reporte-operativo: la fila de
    // "Servicios sueltos · Alojamiento" y la columna "Hotel" (contexto, dentro de
    // otros grupos) mostraban EXACTAMENTE la misma reserva de hotel dos veces —
    // no son 2 hechos distintos, es 1 solo hecho visto desde 2 ángulos. Acá se
    // quita la fila de "Servicios sueltos · Alojamiento" para un pasajero+día
    // SOLO cuando ese mismo pasajero ya aparece en algún OTRO grupo ese mismo
    // día (donde la columna Hotel ya lo muestra) — si el hospedaje es lo ÚNICO
    // que tiene ese día (día de llegada/descanso/checkout, sin ningún tour), la
    // fila se queda: es la única forma de que ese hospedaje aparezca en el
    // reporte, suprimirla ahí SÍ escondería información real.
    //
    // Guard de seguridad: si un pasajero tiene más de UNA reserva de hotel
    // distinta ese mismo día (dato ambiguo — hotelDelDiaPorPasajero() solo
    // captura una), NO se suprime ninguna de sus filas de Alojamiento. Preferible
    // mostrar una fila "de más" (la duplicada de la que sí se ve en Hotel) que
    // arriesgarse a borrar en silencio una reserva real que la columna Hotel
    // nunca llegó a reflejar.
    private function suprimirAlojamientoDuplicado(array &$porFecha, array $hotelDelDia): void
    {
        foreach ($porFecha as $fecha => &$dia) {
            if (! isset($dia['grupos'][self::GRUPO_ALOJAMIENTO])) {
                continue;
            }

            $pasajerosEnOtroGrupo = [];
            foreach ($dia['grupos'] as $grupoKey => $grupo) {
                if ($grupoKey === self::GRUPO_ALOJAMIENTO) {
                    continue;
                }
                foreach (array_keys($grupo['pasajeros']) as $pasajeroId) {
                    $pasajerosEnOtroGrupo[$pasajeroId] = true;
                }
            }

            foreach (array_keys($dia['grupos'][self::GRUPO_ALOJAMIENTO]['pasajeros']) as $pasajeroId) {
                $reservasHotelEseDia = count($hotelDelDia[$fecha . '_' . $pasajeroId]['reserva_item_ids'] ?? []);

                if (isset($pasajerosEnOtroGrupo[$pasajeroId]) && $reservasHotelEseDia <= 1) {
                    unset($dia['grupos'][self::GRUPO_ALOJAMIENTO]['pasajeros'][$pasajeroId]);
                }
            }

            if (empty($dia['grupos'][self::GRUPO_ALOJAMIENTO]['pasajeros'])) {
                unset($dia['grupos'][self::GRUPO_ALOJAMIENTO]);
            }
        }
        unset($dia);
    }

    // fecha+pasajero → info de la reserva de hotel de ese pasajero ese día
    // ('texto' para mostrar en la columna Hotel de otros grupos —
    // reutiliza $fila['servicio'], que para un ítem de hospedaje YA incluye el
    // tipo de habitación ("Cumbaza Hotel y Convenciones · triple"), así no se
    // pierde ese dato al suprimir la fila duplicada de Alojamiento;
    // 'reserva_item_ids' para el guard de suprimirAlojamientoDuplicado()) —
    // sacado de CUALQUIER fila de ese pasajero/fecha cuyo ítem sea de hospedaje,
    // independiente del grupo al que pertenezca esa fila puntual.
    private function hotelDelDiaPorPasajero(array $filas): array
    {
        $mapa = [];
        foreach ($filas as $fila) {
            if (! $fila['hotel']) {
                continue;
            }

            $key = $fila['fecha'] . '_' . $fila['pasajero']['id'];
            $mapa[$key] ??= ['texto' => $fila['servicio'], 'reserva_item_ids' => []];
            $mapa[$key]['reserva_item_ids'][$fila['reserva_item_id']] = true;
        }

        return $mapa;
    }

    // Vuelo deja de vivir en columnas y pasa a ser una fila de servicio más
    // ("Vuelo ida"/"Vuelta", propio o (agencia)), ubicada en la fecha REAL del
    // vuelo — no en la fecha del reserva_item que la trae. Reutiliza los datos ya
    // resueltos en cada $fila (vuelo_ida/vuelo_vuelta = propio del pasajero;
    // vuelo_agencia_ida/vuelta = vendido por la agencia, solo en la fila del ítem
    // pasaje_aereo) sin ninguna query nueva.
    //
    // El ítem pasaje_aereo en sí (origen_tipo=ORIGEN_PASAJE_AEREO) se EXCLUYE de
    // las filas normales: su única función pasa a ser aportar los datos de la
    // fila sintética de vuelo (agencia) — mostrarlo también como fila normal
    // duplicaría la información.
    private function extenderConFilasDeVuelo(array $filas, Carbon $fechaDesde, Carbon $fechaHasta): array
    {
        $filasNormales = array_values(array_filter(
            $filas,
            fn (array $f) => $f['origen_tipo'] !== AlternativaItem::ORIGEN_PASAJE_AEREO
        ));

        $filasVuelo = [];

        foreach ($filas as $fila) {
            if ($fila['origen_tipo'] !== AlternativaItem::ORIGEN_PASAJE_AEREO) {
                continue;
            }
            if ($fila['vuelo_agencia_ida']) {
                $filasVuelo[] = $this->filaSinteticaVuelo($fila, 'Vuelo ida (agencia)', $fila['vuelo_agencia_ida'], $fila['checkin_realizado']);
            }
            if ($fila['vuelo_agencia_vuelta']) {
                $filasVuelo[] = $this->filaSinteticaVuelo($fila, 'Vuelo vuelta (agencia)', $fila['vuelo_agencia_vuelta'], $fila['checkin_realizado']);
            }
        }

        // Vuelo propio del pasajero: es informativo del PASAJERO, no de un
        // reserva_item — mismo dato repetido en todas sus filas, se toma de
        // cualquiera de ellas una sola vez por pasajero (sin checkin, no hay
        // ítem real detrás).
        $pasajerosVistos = [];
        foreach ($filas as $fila) {
            $pasajeroId = $fila['pasajero']['id'];
            if (isset($pasajerosVistos[$pasajeroId])) {
                continue;
            }
            $pasajerosVistos[$pasajeroId] = true;

            if ($fila['vuelo_ida']) {
                $filasVuelo[] = $this->filaSinteticaVuelo($fila, 'Vuelo ida', $fila['vuelo_ida'], null);
            }
            if ($fila['vuelo_vuelta']) {
                $filasVuelo[] = $this->filaSinteticaVuelo($fila, 'Vuelo vuelta', $fila['vuelo_vuelta'], null);
            }
        }

        $fechaDesdeSoloFecha = $fechaDesde->copy()->startOfDay();
        $fechaHastaSoloFecha = $fechaHasta->copy()->startOfDay();

        $filasVuelo = array_filter($filasVuelo, function (array $f) use ($fechaDesdeSoloFecha, $fechaHastaSoloFecha) {
            if (! $f['fecha']) {
                return false;
            }

            return Carbon::parse($f['fecha'])->startOfDay()->between($fechaDesdeSoloFecha, $fechaHastaSoloFecha);
        });

        return array_merge($filasNormales, array_values($filasVuelo));
    }

    private function filaSinteticaVuelo(array $filaOrigen, string $servicio, array $vuelo, ?bool $checkin): array
    {
        $numero = $vuelo['numero'] ?? null;
        $destino = $numero ? "{$vuelo['aerolinea']} · N° {$numero}" : ($vuelo['aerolinea'] ?? '');

        return [
            'fecha' => $vuelo['fecha'] ?? null,
            'hora' => $vuelo['hora'] ?? null,
            'servicio' => $servicio,
            'destino' => $destino,
            'hotel' => null,
            'servicio_nombre' => null,
            'origen_tipo' => 'vuelo',
            'tour_origen_id' => null,
            'tour_origen_nombre' => null,
            'salida_operativa_id' => null,
            'salida_vehiculo' => null,
            'guia' => null,
            'sin_guia' => false,
            'checkin_realizado' => $checkin,
            'pasajero' => $filaOrigen['pasajero'],
            'reserva_id' => $filaOrigen['reserva_id'],
        ];
    }

    // Clave del grupo "Servicios sueltos · Alojamiento" — referenciada también en
    // armarVistaAgrupada() para suprimir la columna "Hotel" (contexto) justo en
    // este grupo: ahí la fila YA ES la reserva de hotel (columna "Servicio"), así
    // que repetir el nombre en "Hotel" es 100% redundante en esa fila puntual —
    // en el resto de grupos (Tours, otros "Servicios sueltos") sigue siendo dato
    // nuevo y se mantiene.
    private const GRUPO_ALOJAMIENTO = 'sueltos_alojamiento';

    // A qué grupo pertenece una fila: Tour (por tour_origen_id) o "Servicios
    // sueltos · {categoría}" — devuelve [clave_unica, nombre_visible, es_tour].
    private function resolverGrupo(array $fila): array
    {
        if ($fila['origen_tipo'] === 'vuelo') {
            return ['sueltos_vuelo', 'Servicios sueltos · Vuelo', false];
        }

        if (! empty($fila['tour_origen_id'])) {
            return ['tour_' . $fila['tour_origen_id'], $fila['tour_origen_nombre'] ?? 'Tour', true];
        }

        if ($fila['hotel']) {
            return [self::GRUPO_ALOJAMIENTO, 'Servicios sueltos · Alojamiento', false];
        }

        $categoria = $fila['servicio_nombre'] ?: $this->categoriaOrigenTipo($fila['origen_tipo']);

        return ['sueltos_' . Str::slug($categoria), 'Servicios sueltos · ' . $categoria, false];
    }

    private function categoriaOrigenTipo(?string $origenTipo): string
    {
        return match ($origenTipo) {
            AlternativaItem::ORIGEN_MANUAL => 'Ítem manual',
            AlternativaItem::ORIGEN_MAYORISTA => 'Paquete mayorista',
            AlternativaItem::ORIGEN_GUIA => 'Guía',
            default => 'Otros servicios',
        };
    }
}
