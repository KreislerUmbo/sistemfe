<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\TourItinerarioItem;
use App\Services\AgenciaViajes\ComboExplosionService;
use App\Services\AgenciaViajes\ComboValidationService;
use App\Services\AgenciaViajes\FotoUploadService;
use App\Services\StorageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Catálogo de paquetes/tours de plantilla — Sesión 11b2
// (plan-hoja-de-ruta-ejecucion.md fila 11b2). plan-modulo-cotizaciones-reservas.md
// §3.7 + plan-modulo-tours-catalogo.md §5 ("tour" y paquetes_plantilla son la
// misma entidad). Header CRUD acá; items_incluidos/itinerario/matriz de hotel
// van en sus propios controllers anidados (mismo patrón que
// GuiaController/GuiaTarifaController de Sesión 11a).
//
// Sesión 11b4: agrega tipo='paquete_combo' (agrupa 2+ tours_simple).
// ComboValidationService/ComboExplosionService inyectados — mismo criterio
// que el resto del vertical (servicios de dominio puros, testeables sin
// Request/Response).
class PaquetePlantillaController extends Controller
{
    public function __construct(
        private ComboValidationService $comboValidation,
        private ComboExplosionService $comboExplosion,
        private FotoUploadService $fotoUploadService
    ) {
    }

    public function index(Request $request)
    {
        $query = PaquetePlantilla::query();

        if ($request->filled('categoria')) {
            $query->where('categoria', $request->get('categoria'));
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->get('tipo'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%")
                    ->orWhere('codigo', 'ilike', "%{$search}%");
            });
        }

        $paquetes = $query->with('destinoAtractivo')->orderByDesc('id')->paginate(15);

        // precio_calculado: SOLO para paquete_combo — su precio no tiene
        // sentido como valor guardado (depende en vivo de los tours
        // incluidos + descuento), ver comentario en show(). tour_simple
        // sigue mostrando su precio_venta_final tal cual (columna existente
        // desde Sesión 6, comportamiento sin cambios).
        $paquetes->getCollection()->transform(function (PaquetePlantilla $paquete) {
            if ($paquete->esPaqueteCombo()) {
                $paquete->setAttribute('precio_calculado', $this->comboExplosion->totalesCombo($paquete));
            }
            $paquete->setAttribute('fotos', StorageUrl::resolveMuchas($paquete->fotos ?? []));

            return $paquete;
        });

        return response()->json([
            'total' => $paquetes->total(),
            'paginate' => 15,
            'paquetes_plantilla' => $paquetes->items(),
        ]);
    }

    public function store(Request $request)
    {
        $validado = $this->validarPayload($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $rechazadas = [];
        if ($request->hasFile('fotos')) {
            try {
                $resultado = $this->fotoUploadService->procesarLote((array) $request->file('fotos'), 'paquetes-plantilla', 0);
            } catch (ValidationException $e) {
                return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
            }
            $validado['fotos'] = $resultado['paths'];
            $rechazadas = $resultado['rechazadas'];
        }

        $paquete = PaquetePlantilla::create($validado);
        $paquete->setAttribute('fotos', StorageUrl::resolveMuchas($paquete->fotos ?? []));

        return response()->json([
            'code' => 200,
            'message' => 'Paquete/tour registrado correctamente',
            'paquete_plantilla' => $paquete,
            'fotos_rechazadas' => $rechazadas,
        ]);
    }

    public function show(string $id)
    {
        $paquete = PaquetePlantilla::with([
            'destinoAtractivo',
            'items.proveedorTarifa.proveedorServicio.proveedor',
            'items.guiaTarifa.guia',
            'items.paquetePlantillaHijo',
            'paqueteItinerario' => fn ($q) => $q->orderBy('dia_relativo')->orderBy('orden'),
            'paqueteItinerario.destinoAtractivo',
        ])->findOrFail($id);

        $hoteles = OpcionHotel::where('paquete_plantilla_id', $paquete->id)->with('opcionesHotelTarifas')->get();

        // precio_calculado/itinerario_derivado: SIEMPRE en vivo, nunca un
        // valor stale — ver punto 3/5 del diseño de Sesión 11b4. Sin caché:
        // no existía ninguna capa de caché sobre este endpoint antes de esta
        // sesión, así que "calcular en vivo" ya cumple la regla sin
        // necesidad de mecanismo de invalidación nuevo.
        $datosCombo = null;
        if ($paquete->esPaqueteCombo()) {
            $datosCombo = [
                'precio_calculado' => $this->comboExplosion->totalesCombo($paquete),
                'itinerario_derivado' => $this->comboExplosion->itinerarioDerivado($paquete),
                'tours_incluidos' => $this->comboExplosion->toursDelCombo($paquete)->values(),
            ];
        }

        $paquete->setAttribute('fotos', StorageUrl::resolveMuchas($paquete->fotos ?? []));

        return response()->json([
            'paquete_plantilla' => $paquete,
            'opciones_hotel' => $hoteles,
            'combo' => $datosCombo,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $paquete = PaquetePlantilla::findOrFail($id);

        $validado = $this->validarPayload($request, $paquete->id);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        // No se permite cambiar 'tipo' una vez que el paquete ya tiene ítems
        // cargados — mismo criterio ya usado en SerieComprobanteController
        // para campos que "cierran" al tener datos reales dependientes.
        if (array_key_exists('tipo', $validado) && $validado['tipo'] !== $paquete->tipo && $paquete->items()->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede cambiar el tipo de un paquete que ya tiene ítems cargados. Eliminá los ítems primero.',
            ], 422);
        }

        // Bloqueo de desactivación (punto 10 del diseño): un tour_simple que
        // esté incluido en al menos un combo ACTIVO no se puede desactivar
        // en silencio. El admin puede forzarlo explícitamente
        // (forzar_desactivacion=true) — en ese caso queda como "componente
        // inactivo": ComboExplosionService::totalesCombo() ya lo excluye del
        // cálculo y reporta la lista en 'componentes_inactivos'.
        if (
            array_key_exists('activo', $validado)
            && $validado['activo'] === false
            && $paquete->activo === true
            && $paquete->esTourSimple()
        ) {
            $combosAfectados = $this->comboValidation->combosActivosQueLoIncluyen($paquete);

            if ($combosAfectados->isNotEmpty() && ! $request->boolean('forzar_desactivacion')) {
                return response()->json([
                    'code' => 422,
                    'message' => 'Este tour está incluido en '.$combosAfectados->count().' combo(s) activo(s): '
                        .$combosAfectados->pluck('nombre')->implode(', ')
                        .'. Reenviá con forzar_desactivacion=true para desactivarlo igual (quedará marcado como componente inactivo en esos combos, sin romper su cálculo de precio).',
                    'combos_afectados' => $combosAfectados->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'codigo' => $c->codigo])->values(),
                ], 422);
            }
        }

        // Piso de margen del combo (punto 3 del diseño): se revalida cada
        // vez que se edita descuento_tipo/descuento_valor/margen_minimo_pct,
        // simulado contra los tours YA cargados (sin persistir todavía).
        $tipoFinal = $validado['tipo'] ?? $paquete->tipo;
        if ($tipoFinal === PaquetePlantilla::TIPO_PAQUETE_COMBO) {
            $margenMinimoFinal = array_key_exists('margen_minimo_pct', $validado)
                ? $validado['margen_minimo_pct']
                : $paquete->margen_minimo_pct;

            if ($margenMinimoFinal !== null) {
                $paqueteSimulado = (clone $paquete)->fill($validado);
                $totales = $this->comboExplosion->totalesCombo($paqueteSimulado);

                $errorMargen = $this->comboValidation->validarMargenMinimo(
                    $totales['costo_total_combo'],
                    $totales['venta_neta_combo'],
                    (float) $margenMinimoFinal
                );

                if ($errorMargen !== null) {
                    return response()->json(['code' => 422, 'message' => $errorMargen], 422);
                }
            }
        }

        $rechazadas = [];
        if ($request->hasFile('fotos')) {
            try {
                $resultado = $this->fotoUploadService->procesarLote(
                    (array) $request->file('fotos'),
                    'paquetes-plantilla',
                    count($paquete->fotos ?? [])
                );
            } catch (ValidationException $e) {
                return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
            }
            $validado['fotos'] = array_merge($paquete->fotos ?? [], $resultado['paths']);
            $rechazadas = $resultado['rechazadas'];
        }

        $paquete->update($validado);
        $paquete->setAttribute('fotos', StorageUrl::resolveMuchas($paquete->fotos ?? []));

        return response()->json([
            'code' => 200,
            'message' => 'Paquete/tour actualizado correctamente',
            'paquete_plantilla' => $paquete,
            'fotos_rechazadas' => $rechazadas,
        ]);
    }

    // Sin guard externo: nada fuera de este propio árbol referencia
    // paquete_plantilla_id todavía (11b3 — cargar alternativa desde
    // plantilla — no está construido). Cascada completa de lo que sí es
    // propio (items/itinerario/matriz de hotel), en transacción.
    public function destroy(string $id)
    {
        $paquete = PaquetePlantilla::findOrFail($id);

        DB::transaction(function () use ($paquete) {
            PaquetePlantillaItem::where('paquete_plantilla_id', $paquete->id)->delete();
            TourItinerarioItem::where('tour_id', $paquete->id)->delete();

            $hotelesIds = OpcionHotel::where('paquete_plantilla_id', $paquete->id)->pluck('id');
            OpcionHotelTarifa::whereIn('opcion_hotel_id', $hotelesIds)->delete();
            OpcionHotel::whereIn('id', $hotelesIds)->delete();

            foreach ($paquete->fotos ?? [] as $foto) {
                if (Storage::disk('public')->exists($foto)) {
                    Storage::disk('public')->delete($foto);
                }
            }

            $paquete->delete();
        });

        return response()->json(['code' => 200, 'message' => 'Paquete/tour eliminado correctamente']);
    }

    // Matriz hotel × tipo de habitación — mismo motor que
    // OpcionMayoristaController::hoteles() (§2.4/§3.7: "un solo motor para
    // las 3 categorías de paquete"), escopeado a paquete_plantilla_id en
    // vez de opcion_mayorista_id. Sin extraer a un service compartido: la
    // validación de "proveedor debe ser tipo Mayorista" de OpcionMayorista
    // no aplica acá (proveedor_id es opcional y libre), así que el
    // duplicado es más simple que forzar una abstracción común.
    public function hoteles(Request $request, string $paqueteId)
    {
        $paquete = PaquetePlantilla::findOrFail($paqueteId);

        if ($request->isMethod('get')) {
            $hoteles = OpcionHotel::where('paquete_plantilla_id', $paquete->id)->with('opcionesHotelTarifas')->get();

            return response()->json(['opciones_hotel' => $hoteles]);
        }

        $validator = Validator::make($request->all(), [
            'nombre_hotel' => 'required|string|max:250',
            'categoria_estrellas' => 'nullable|integer|min:1|max:5',
            'proveedor_id' => 'nullable|integer|exists:proveedores,id',
            'moneda' => 'nullable|in:PEN,USD',
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

        $hotel = DB::transaction(function () use ($paquete, $validado) {
            $hotel = OpcionHotel::create([
                'paquete_plantilla_id' => $paquete->id,
                'nombre_hotel' => $validado['nombre_hotel'],
                'categoria_estrellas' => $validado['categoria_estrellas'] ?? null,
                'proveedor_id' => $validado['proveedor_id'] ?? null,
                'moneda' => $validado['moneda'] ?? 'PEN',
            ]);

            foreach ($validado['tarifas'] ?? [] as $tarifa) {
                // Snapshot al momento de guardar — el accessor de
                // OpcionHotelTarifa recalcula "en vivo" mientras la relación
                // siga viva, pero el valor guardado no debería quedar en
                // blanco si el día de mañana se borra la tarifa real.
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

        return response()->json(['code' => 200, 'message' => 'Hotel agregado correctamente', 'opcion_hotel' => $hotel]);
    }

    public function eliminarHotel(string $id)
    {
        $hotel = OpcionHotel::findOrFail($id);

        DB::transaction(function () use ($hotel) {
            OpcionHotelTarifa::where('opcion_hotel_id', $hotel->id)->delete();
            $hotel->delete();
        });

        return response()->json(['code' => 200, 'message' => 'Hotel quitado del paquete correctamente']);
    }

    private function validarPayload(Request $request, ?int $ignoreId = null): array|JsonResponse
    {
        // Tolerancia de entrada: si llega "HH:MM:SS" (p.ej. un valor de
        // hora_salida/hora_retorno recargado desde la BD y reenviado sin
        // pasar por el accessor del modelo — carga de plantilla en el
        // cotizador, un futuro "duplicar tour", etc.) se recorta a "HH:MM"
        // ANTES de validar, en vez de rechazar con 422 un formato que el
        // propio backend produce. date_format:H:i abajo sigue siendo la
        // única fuente de verdad sobre qué es válido.
        foreach (['hora_salida', 'hora_retorno'] as $campoHora) {
            if (is_string($request->input($campoHora)) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $request->input($campoHora))) {
                $request->merge([$campoHora => substr($request->input($campoHora), 0, 5)]);
            }
        }

        $validator = Validator::make($request->all(), [
            'codigo' => ['nullable', 'string', 'max:50', Rule::unique('paquetes_plantilla', 'codigo')->ignore($ignoreId)],
            'categoria' => 'required|in:local,nacional,internacional',
            'tipo' => 'nullable|in:tour_simple,paquete_combo',
            'nombre' => 'required|string|max:250',
            'descripcion' => 'nullable|string',
            'destino_atractivo_id' => 'required|integer|exists:destinos_atractivos,id',
            'duracion_horas' => 'required|integer|min:1',
            'hora_salida' => 'nullable|date_format:H:i',
            'hora_retorno' => 'nullable|date_format:H:i',
            'lugar_recojo' => 'nullable|string',
            'no_incluye' => 'nullable|string',
            'recomendaciones' => 'nullable|string',
            'vuelo_incluido' => 'nullable|boolean',
            'vuelo_aerolinea' => 'nullable|string|max:150',
            'vuelo_detalle' => 'nullable|string',
            'precio_venta_final' => 'nullable|numeric|min:0',
            'vigencia_desde' => 'nullable|date',
            'vigencia_hasta' => 'nullable|date|after_or_equal:vigencia_desde',
            'publicado_web' => 'nullable|boolean',
            'activo' => 'nullable|boolean',
            'descuento_tipo' => 'nullable|in:porcentaje,monto',
            'descuento_valor' => 'nullable|numeric|min:0',
            // Solo valida que sea una imagen válida acá — el límite de 5MB es
            // responsabilidad de FotoUploadService, que rechaza esa foto en
            // particular sin bloquear el resto del lote (a diferencia de un
            // 'max:' acá, que tumbaría la request completa).
            'fotos' => 'nullable|array',
            'fotos.*' => 'image',
            'margen_minimo_pct' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        // precio_venta_final no aplica a paquete_combo — se calcula en vivo
        // desde los tours incluidos + descuento (punto 3 del diseño de
        // Sesión 11b4, ver ComboExplosionService::totalesCombo()). Rechazado
        // explícito, no ignorado en silencio, para que quien mande ese
        // campo se entere de que no tiene efecto acá.
        if (($validado['tipo'] ?? null) === PaquetePlantilla::TIPO_PAQUETE_COMBO && ! empty($validado['precio_venta_final'])) {
            return response()->json([
                'code' => 422,
                'message' => 'precio_venta_final no aplica a paquete_combo — se calcula en vivo desde los tours incluidos y el descuento configurado.',
            ], 422);
        }

        return $validado;
    }
}
