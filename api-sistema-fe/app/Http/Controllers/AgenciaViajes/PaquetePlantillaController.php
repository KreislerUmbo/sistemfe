<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\TourItinerarioItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

// Catálogo de paquetes/tours de plantilla — Sesión 11b2
// (plan-hoja-de-ruta-ejecucion.md fila 11b2). plan-modulo-cotizaciones-reservas.md
// §3.7 + plan-modulo-tours-catalogo.md §5 ("tour" y paquetes_plantilla son la
// misma entidad). Header CRUD acá; items_incluidos/itinerario/matriz de hotel
// van en sus propios controllers anidados (mismo patrón que
// GuiaController/GuiaTarifaController de Sesión 11a).
class PaquetePlantillaController extends Controller
{
    public function index(Request $request)
    {
        $query = PaquetePlantilla::query();

        if ($request->filled('categoria')) {
            $query->where('categoria', $request->get('categoria'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%")
                    ->orWhere('codigo', 'ilike', "%{$search}%");
            });
        }

        $paquetes = $query->with('destinoAtractivo')->orderByDesc('id')->paginate(15);

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

        if ($request->hasFile('fotos')) {
            $validado['fotos'] = $this->subirFotos($request);
        }

        $paquete = PaquetePlantilla::create($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Paquete/tour registrado correctamente',
            'paquete_plantilla' => $paquete,
        ]);
    }

    public function show(string $id)
    {
        $paquete = PaquetePlantilla::with([
            'destinoAtractivo',
            'items.proveedorTarifa.proveedorServicio.proveedor',
            'items.guiaTarifa.guia',
            'paqueteItinerario' => fn ($q) => $q->orderBy('dia_relativo')->orderBy('orden'),
            'paqueteItinerario.destinoAtractivo',
        ])->findOrFail($id);

        $hoteles = OpcionHotel::where('paquete_plantilla_id', $paquete->id)->with('opcionesHotelTarifas')->get();

        return response()->json([
            'paquete_plantilla' => $paquete,
            'opciones_hotel' => $hoteles,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $paquete = PaquetePlantilla::findOrFail($id);

        $validado = $this->validarPayload($request, $paquete->id);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        if ($request->hasFile('fotos')) {
            $validado['fotos'] = array_merge($paquete->fotos ?? [], $this->subirFotos($request));
        }

        $paquete->update($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Paquete/tour actualizado correctamente',
            'paquete_plantilla' => $paquete,
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
            'tarifas' => 'nullable|array',
            'tarifas.*.tipo_habitacion' => 'required_with:tarifas|in:simple,matrimonial,doble,triple,familiar',
            'tarifas.*.precio_costo' => 'required_with:tarifas|numeric|min:0',
            'tarifas.*.precio_venta' => 'required_with:tarifas|numeric|min:0',
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
            ]);

            foreach ($validado['tarifas'] ?? [] as $tarifa) {
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

    private function subirFotos(Request $request): array
    {
        $paths = [];
        foreach ((array) $request->file('fotos') as $foto) {
            $paths[] = Storage::disk('public')->putFile('paquetes-plantilla', $foto);
        }

        return $paths;
    }

    private function validarPayload(Request $request, ?int $ignoreId = null): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'codigo' => ['nullable', 'string', 'max:50', Rule::unique('paquetes_plantilla', 'codigo')->ignore($ignoreId)],
            'categoria' => 'required|in:local,nacional,internacional',
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
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        return $validator->validated();
    }
}
