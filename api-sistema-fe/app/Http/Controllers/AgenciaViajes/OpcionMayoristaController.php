<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ContenidoTour;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;
use App\Models\AgenciaViajes\OpcionMayoristaTour;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\ReservaItem;
use App\Services\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

// Comparador de mayoristas — plan-modulo-cotizaciones-reservas.md §2.4.
class OpcionMayoristaController extends Controller
{
    public function index(string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);

        $opciones = OpcionMayorista::where('alternativa_id', $alternativa->id)
            ->with(['proveedor', 'opcionesHotel.opcionesHotelTarifas', 'opcionales', 'tours.paquetePlantilla'])
            ->get();

        // 05-sep-2026 — fotos por hotel (máx. 3), mismo criterio de
        // resolver a URL completa antes de mandar al frontend que
        // PaquetePlantillaController/DestinoAtractivoController.
        $opciones->each(fn (OpcionMayorista $op) => $op->opcionesHotel->each(
            fn (OpcionHotel $h) => $h->setAttribute('fotos', OpcionHotel::fotosResueltas($h->fotos ?? []))
        ));

        // Hallazgo del usuario (05-sep-2026): las fotos de un tour incluido
        // (PaquetePlantilla, ver TourIncluidoForm.vue) SÍ se resuelven en
        // PaquetePlantillaController (index/show/store/update), pero acá
        // llegan anidadas vía tours.paquetePlantilla sin pasar por ninguno
        // de esos métodos — sin este resolve, el frontend recibía el path
        // relativo crudo ("paquetes-plantilla/foto_x.jpg") y el navegador lo
        // resolvía como URL relativa a la página actual, rompiendo la
        // miniatura al editar el tour.
        $opciones->each(fn (OpcionMayorista $op) => $op->tours->each(
            fn (OpcionMayoristaTour $t) => $t->paquetePlantilla?->setAttribute('fotos', StorageUrl::resolveMuchas($t->paquetePlantilla->fotos ?? []))
        ));

        return response()->json(['opciones_mayorista' => $opciones]);
    }

    public function store(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);

        $validator = Validator::make($request->all(), [
            'proveedor_id' => 'required|integer|exists:proveedores,id',
            'salida_mayorista_id' => 'nullable|integer|exists:salidas_mayorista,id',
            'moneda' => 'required|in:PEN,USD',
            'incluye' => 'nullable|string',
            // Simulación Panamá (04-sep-2026) — "No incluye" del paquete
            // base, mismo campo que ya existía para cada tour opcional
            // (opcion_mayorista_opcional.no_incluye).
            'no_incluye' => 'nullable|string',
            // Fix C1 (02-sep-2026) — único texto que
            // AlternativaController::resolverNombreItemPdf() puede
            // imprimir en el PDF comercial para esta opción.
            'descripcion_publica' => 'nullable|string|max:255',
            'notas' => 'nullable|string',
            'vuelo_aerolinea' => 'nullable|string|max:150',
            'vuelo_detalle' => 'nullable|string',
            // Sesión 12e — vínculo opcional a la biblioteca de contenido
            // reutilizable (§9.1 de la auditoría).
            'contenido_tour_id' => 'nullable|integer|exists:contenido_tour,id',
            // Sesión 12f-2 — con 2+ destinos, el chip activo del cotizador
            // manda cuál. Gap real encontrado planificando esa sesión: este
            // endpoint (a diferencia de los 9 de AlternativaItemController,
            // ya corregidos en 12f-1) todavía forzaba SIEMPRE el primer
            // destino de la alternativa, ignorando cualquier valor explícito.
            'alternativa_destino_id' => ['nullable', 'integer', Rule::exists('alternativa_destinos', 'id')->where('alternativa_id', $alternativa->id)],
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $proveedor = Proveedor::findOrFail($validado['proveedor_id']);
        $esMayorista = $proveedor->tipo_id && ProveedorTipo::where('id', $proveedor->tipo_id)->where('slug', ProveedorTipo::SLUG_MAYORISTA)->exists();

        if (! $esMayorista) {
            return response()->json(['code' => 422, 'message' => 'El proveedor seleccionado no es de tipo Mayorista.'], 422);
        }

        $contenidoTourId = $validado['contenido_tour_id'] ?? null;
        unset($validado['contenido_tour_id']);
        $alternativaDestinoId = $validado['alternativa_destino_id'] ?? null;
        unset($validado['alternativa_destino_id']);

        // Sesión 12d/12f-2 — alternativa_destino_id explícito si viene (y
        // pertenece a esta alternativa, ya validado arriba), si no el
        // primer destino por defecto — nunca por separado de
        // alternativa_id, para no desincronizarlos (§20 de la auditoría).
        $opcion = OpcionMayorista::create($validado + [
            'alternativa_id' => $alternativa->id,
            'alternativa_destino_id' => $alternativaDestinoId ?? $alternativa->destinos()->value('id'),
            ...$this->resolverSnapshotContenidoTour($contenidoTourId),
            'estado' => 'candidata',
        ]);

        return response()->json(['code' => 200, 'message' => 'Opción de mayorista agregada correctamente', 'opcion_mayorista' => $opcion]);
    }

    // Marca esta opción como 'elegida'. Solo se reasigna la que ya era
    // 'elegida' (si había) de vuelta a 'candidata' — el resto de
    // comparadas/descartadas NO se tocan (§2.4: quedan como historial de
    // por qué se eligió esta). El vendedor puede cambiar de elegida
    // después, llamando de nuevo a este mismo endpoint sobre otra opción.
    public function elegir(string $id)
    {
        $opcion = OpcionMayorista::findOrFail($id);

        DB::transaction(function () use ($opcion) {
            OpcionMayorista::where('alternativa_id', $opcion->alternativa_id)
                ->where('estado', 'elegida')
                ->update(['estado' => 'candidata']);

            $opcion->update(['estado' => 'elegida']);
        });

        return response()->json(['code' => 200, 'message' => 'Opción marcada como elegida', 'opcion_mayorista' => $opcion->fresh()]);
    }

    // Corregir metadata de una opción ya creada (proveedor/moneda/incluye/
    // vuelo/notas/descripción pública). No toca alternativa_id/
    // alternativa_destino_id/estado. Update directo sin versionado: a
    // diferencia de ProveedorTarifaController, estos campos son metadata
    // descriptiva — el precio real ya vive congelado por ítem en
    // opciones_hotel_tarifas, editar acá no lo toca.
    public function update(Request $request, string $id)
    {
        $opcion = OpcionMayorista::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'proveedor_id' => 'required|integer|exists:proveedores,id',
            'moneda' => 'required|in:PEN,USD',
            'incluye' => 'nullable|string',
            'no_incluye' => 'nullable|string',
            'descripcion_publica' => 'nullable|string|max:255',
            'notas' => 'nullable|string',
            'vuelo_aerolinea' => 'nullable|string|max:150',
            'vuelo_detalle' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $proveedor = Proveedor::findOrFail($validado['proveedor_id']);
        $esMayorista = $proveedor->tipo_id && ProveedorTipo::where('id', $proveedor->tipo_id)->where('slug', ProveedorTipo::SLUG_MAYORISTA)->exists();

        if (! $esMayorista) {
            return response()->json(['code' => 422, 'message' => 'El proveedor seleccionado no es de tipo Mayorista.'], 422);
        }

        $opcion->update($validado);

        return response()->json(['code' => 200, 'message' => 'Opción actualizada correctamente', 'opcion_mayorista' => $opcion->fresh()]);
    }

    // Sacarla de la comparación sin borrar nada (reversible vía
    // reactivar()) — mismo espíritu que ProveedorTarifaController::
    // desactivar()/activar(), pero sobre el estado que opcion_mayorista ya
    // tenía previsto en su schema desde el diseño original ('descartada'
    // nunca se escribía hasta ahora).
    public function descartar(string $id)
    {
        $opcion = OpcionMayorista::findOrFail($id);
        $opcion->update(['estado' => 'descartada']);

        return response()->json(['code' => 200, 'message' => 'Opción descartada', 'opcion_mayorista' => $opcion->fresh()]);
    }

    public function reactivar(string $id)
    {
        $opcion = OpcionMayorista::findOrFail($id);
        $opcion->update(['estado' => 'candidata']);

        return response()->json(['code' => 200, 'message' => 'Opción reactivada', 'opcion_mayorista' => $opcion->fresh()]);
    }

    // DELETE opciones-mayorista/{id} — borrado real del bloque completo,
    // distinto de descartar() (que solo lo oculta). Mismos dos guards ya
    // establecidos en el vertical: alternativa ya aceptada (mismo mensaje
    // que AlternativaItemController::elegirOpcionGrupo()) y reserva ya
    // generada sobre algún ítem de esta opción (mismo criterio que
    // AlternativaItemController::destroy()). En transacción: borra los
    // AlternativaItem de esta opción, sus OpcionHotelTarifa/OpcionHotel,
    // OpcionMayoristaOpcional, y los vínculos OpcionMayoristaTour (nunca el
    // PaquetePlantilla real, mismo criterio que quitarTour()) — y por
    // último la OpcionMayorista.
    public function eliminar(string $id)
    {
        $opcion = OpcionMayorista::with('alternativa')->findOrFail($id);
        $alternativa = $opcion->alternativa;

        if ($alternativa->estado === 'aceptada') {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa ya fue aceptada y generó una reserva — esta opción ya no se puede eliminar desde acá.',
            ], 422);
        }

        $itemIds = AlternativaItem::where('opcion_mayorista_id', $opcion->id)->pluck('id');

        if (ReservaItem::whereIn('alternativa_item_id', $itemIds)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: uno o más ítems de esta opción ya tienen una reserva generada. Cancelá la reserva primero si corresponde.',
            ], 422);
        }

        DB::transaction(function () use ($opcion, $itemIds) {
            AlternativaItem::whereIn('id', $itemIds)->delete();

            $hotelIds = OpcionHotel::where('opcion_mayorista_id', $opcion->id)->pluck('id');
            OpcionHotelTarifa::whereIn('opcion_hotel_id', $hotelIds)->delete();
            OpcionHotel::whereIn('id', $hotelIds)->delete();

            OpcionMayoristaOpcional::where('opcion_mayorista_id', $opcion->id)->delete();
            OpcionMayoristaTour::where('opcion_mayorista_id', $opcion->id)->delete();

            $opcion->delete();
        });

        $total = AlternativaItem::calcularTotalEfectivo($alternativa->items()->get())['total'];
        $alternativa->update(['total' => $total]);

        return response()->json(['code' => 200, 'message' => 'Opción de mayorista eliminada correctamente']);
    }

    // Matriz hotel × tipo de habitación — mismo motor que paquetes_plantilla
    // (§2.4). El payload puede traer 'tarifas' anidado para crear el hotel
    // + su matriz de precios en un solo request.
    public function hoteles(Request $request, string $id)
    {
        $opcion = OpcionMayorista::findOrFail($id);

        if ($request->isMethod('get')) {
            $hoteles = OpcionHotel::where('opcion_mayorista_id', $opcion->id)->with('opcionesHotelTarifas')->get();
            $hoteles->each(fn (OpcionHotel $h) => $h->setAttribute('fotos', OpcionHotel::fotosResueltas($h->fotos ?? [])));

            return response()->json(['opciones_hotel' => $hoteles]);
        }

        $validator = Validator::make($request->all(), [
            'nombre_hotel' => 'required|string|max:250',
            'categoria_estrellas' => 'nullable|integer|min:1|max:5',
            'proveedor_id' => 'nullable|integer|exists:proveedores,id',
            'tarifas' => 'nullable|array',
            'tarifas.*.tipo_habitacion' => 'required_with:tarifas|in:simple,matrimonial,doble,triple,familiar',
            'tarifas.*.precio_costo' => 'required_with:tarifas|numeric|min:0',
            'tarifas.*.precio_venta' => 'required_with:tarifas|numeric|min:0',
            // Sesión 11k, Fix 9 — tarifa real de proveedor, opcional (hotel
            // manual/referencial cuando no viene).
            'tarifas.*.proveedor_tarifa_id' => 'nullable|integer|exists:proveedor_tarifas,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $hotel = DB::transaction(function () use ($opcion, $validado) {
            $hotel = OpcionHotel::create([
                'opcion_mayorista_id' => $opcion->id,
                'nombre_hotel' => $validado['nombre_hotel'],
                'categoria_estrellas' => $validado['categoria_estrellas'] ?? null,
                'proveedor_id' => $validado['proveedor_id'] ?? null,
                // OpcionHotel.moneda es un campo nuevo (Fix 9); para un
                // hotel de mayorista se sincroniza con la moneda de la
                // propia opción — crearItemMayorista() sigue usando
                // $opcion->moneda como fuente real de conversión, esto es
                // solo para que el dato quede consistente en el registro.
                'moneda' => $opcion->moneda,
            ]);

            foreach ($validado['tarifas'] ?? [] as $tarifa) {
                if (! empty($tarifa['proveedor_tarifa_id'])) {
                    $tarifaReal = ProveedorTarifa::find($tarifa['proveedor_tarifa_id']);
                    if ($tarifaReal) {
                        $tarifa['precio_costo'] = $tarifaReal->precio_costo;
                        $tarifa['precio_venta'] = $tarifaReal->precio_venta_adulto;
                    }
                }

                OpcionHotelTarifa::create($tarifa + ['opcion_hotel_id' => $hotel->id]);
            }

            return $hotel;
        });

        $hotel->load('opcionesHotelTarifas');
        $hotel->setAttribute('fotos', OpcionHotel::fotosResueltas($hotel->fotos ?? []));

        return response()->json(['code' => 200, 'message' => 'Hotel agregado correctamente', 'opcion_hotel' => $hotel]);
    }

    // Tours opcionales — nunca se suman automáticamente al total (§2.4).
    public function opcionales(Request $request, string $id)
    {
        $opcion = OpcionMayorista::findOrFail($id);

        if ($request->isMethod('get')) {
            return response()->json(['opcion_mayorista_opcionales' => OpcionMayoristaOpcional::where('opcion_mayorista_id', $opcion->id)->get()]);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:250',
            'precio_por_persona' => 'required|numeric|min:0',
            'moneda' => 'required|in:PEN,USD',
            'incluye' => 'nullable|string',
            'no_incluye' => 'nullable|string',
            'contenido_tour_id' => 'nullable|integer|exists:contenido_tour,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $contenidoTourId = $validado['contenido_tour_id'] ?? null;
        unset($validado['contenido_tour_id']);

        $opcional = OpcionMayoristaOpcional::create($validado + [
            'opcion_mayorista_id' => $opcion->id,
            ...$this->resolverSnapshotContenidoTour($contenidoTourId),
        ]);

        return response()->json(['code' => 200, 'message' => 'Opcional agregado correctamente', 'opcion_mayorista_opcional' => $opcional]);
    }

    // PUT opcion-mayorista-opcionales/{id} — corregir un tour opcional ya
    // cargado. Sin guard de reserva: los opcionales nunca se convierten en
    // AlternativaItem (hueco real ya documentado en memoria de proyecto,
    // fuera de este cambio) — no hay nada "congelado" que proteger todavía.
    public function actualizarOpcional(Request $request, string $id)
    {
        $opcional = OpcionMayoristaOpcional::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:250',
            'precio_por_persona' => 'required|numeric|min:0',
            'moneda' => 'required|in:PEN,USD',
            'incluye' => 'nullable|string',
            'no_incluye' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $opcional->update($validator->validated());

        return response()->json(['code' => 200, 'message' => 'Opcional actualizado correctamente', 'opcion_mayorista_opcional' => $opcional->fresh()]);
    }

    public function eliminarOpcional(string $id)
    {
        $opcional = OpcionMayoristaOpcional::findOrFail($id);
        $opcional->delete();

        return response()->json(['code' => 200, 'message' => 'Opcional eliminado correctamente']);
    }

    // Tours incluidos con itinerario real — distinto de `incluye` (texto plano):
    // vincula un PaquetePlantilla ya creado (con sus propios pasos de
    // itinerario, armado por el mini-form del frontend vía los endpoints que ya
    // existen de Paquetes/Tours, sin backend nuevo para esa parte) a esta
    // opción de mayorista. 'orden' es el "Día" que ve el vendedor — lo lee
    // AlternativaController::itinerarioAlternativa() para encadenar el offset
    // de días de la sección "Itinerario" del PDF, igual que ya hace con los
    // tours de un combo Local/Nacional.
    public function tours(Request $request, string $id)
    {
        $opcion = OpcionMayorista::findOrFail($id);

        if ($request->isMethod('get')) {
            $tours = OpcionMayoristaTour::where('opcion_mayorista_id', $opcion->id)
                ->with('paquetePlantilla')->orderBy('orden')->get();
            $tours->each(fn (OpcionMayoristaTour $t) => $t->paquetePlantilla?->setAttribute('fotos', StorageUrl::resolveMuchas($t->paquetePlantilla->fotos ?? [])));

            return response()->json(['opcion_mayorista_tours' => $tours]);
        }

        // Guardrail (18-sep-2026) — exactamente uno de los 2 caminos:
        // paquete_plantilla_id (tour real del catálogo, comportamiento sin
        // cambios) o nombre+descripcion (ad-hoc, logística de ESTE
        // itinerario — nunca toca el catálogo de Paquetes/Tours). Mismo
        // criterio "exactamente uno" que ya usa ReservaController::
        // reasignarHotel() para catálogo vs. ad-hoc del lado hotel.
        $validator = Validator::make($request->all(), [
            'paquete_plantilla_id' => 'nullable|integer|exists:paquetes_plantilla,id',
            'nombre' => 'nullable|string|max:250',
            'descripcion' => 'nullable|string',
            'orden' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $esCatalogo = ! empty($validado['paquete_plantilla_id']);
        $esAdhoc = ! empty($validado['nombre']);
        if ($esCatalogo === $esAdhoc) {
            return response()->json([
                'code' => 422,
                'message' => 'Elegí exactamente un tour: del catálogo de Paquetes/Tours, o ad-hoc con nombre propio.',
            ], 422);
        }

        $tour = OpcionMayoristaTour::create($validado + ['opcion_mayorista_id' => $opcion->id]);
        $tour->load('paquetePlantilla');
        $tour->paquetePlantilla?->setAttribute('fotos', StorageUrl::resolveMuchas($tour->paquetePlantilla->fotos ?? []));

        return response()->json(['code' => 200, 'message' => 'Tour vinculado correctamente', 'opcion_mayorista_tour' => $tour]);
    }

    // Borra solo el vínculo — el PaquetePlantilla real queda intacto (sin
    // borrarse), reusable después desde el buscador "tour ya existente" de
    // TourIncluidoForm.vue (05-sep-2026) o a mano desde Paquetes/Tours.
    public function quitarTour(string $id)
    {
        $tour = OpcionMayoristaTour::findOrFail($id);
        $tour->delete();

        return response()->json(['code' => 200, 'message' => 'Tour desvinculado correctamente']);
    }

    // PUT opcion-mayorista-tours/{id} — el "Día" (orden) del vínculo, para
    // CUALQUIER tour (real o ad-hoc). El contenido de un tour REAL
    // (nombre/descripción/duración/destino/fotos) se sigue editando aparte,
    // vía paquetePlantillaService.actualizar() — sin cambios. Guardrail
    // (18-sep-2026) — nombre/descripcion acá SOLO aplican a un tour ad-hoc
    // (sin paquete_plantilla_id): son su único lugar de edición, no hay
    // PaquetePlantilla detrás para editar por el otro camino.
    public function actualizarOrdenTour(Request $request, string $id)
    {
        $tour = OpcionMayoristaTour::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'orden' => 'required|integer|min:1',
            'nombre' => 'nullable|string|max:250',
            'descripcion' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        if ($tour->paquete_plantilla_id && (! empty($validado['nombre']) || ! empty($validado['descripcion']))) {
            return response()->json([
                'code' => 422,
                'message' => 'Este tour es del catálogo — su contenido se edita desde Paquetes/Tours, no acá.',
            ], 422);
        }
        if (! $tour->paquete_plantilla_id) {
            $validado = array_intersect_key($validado, array_flip(['orden', 'nombre', 'descripcion']));
        } else {
            $validado = ['orden' => $validado['orden']];
        }

        $tour->update($validado);
        $tour->load('paquetePlantilla');
        $tour->paquetePlantilla?->setAttribute('fotos', StorageUrl::resolveMuchas($tour->paquetePlantilla->fotos ?? []));

        return response()->json(['code' => 200, 'message' => 'Día actualizado correctamente', 'opcion_mayorista_tour' => $tour]);
    }

    // Sesión 12e — compartido por store()/opcionales(): resuelve el
    // ContenidoTour (si vino contenido_tour_id) y copia descripcion/fotos
    // a las columnas snapshot. Un solo punto de escritura para no
    // desincronizar contenido_tour_id vs. el snapshot guardado.
    private function resolverSnapshotContenidoTour(?int $contenidoTourId): array
    {
        if ($contenidoTourId === null) {
            return [
                'contenido_tour_id' => null,
                'contenido_tour_descripcion_snapshot' => null,
                'contenido_tour_fotos_snapshot' => null,
            ];
        }

        $contenidoTour = ContenidoTour::findOrFail($contenidoTourId);

        return [
            'contenido_tour_id' => $contenidoTour->id,
            'contenido_tour_descripcion_snapshot' => $contenidoTour->descripcion,
            'contenido_tour_fotos_snapshot' => $contenidoTour->fotos,
        ];
    }
}
