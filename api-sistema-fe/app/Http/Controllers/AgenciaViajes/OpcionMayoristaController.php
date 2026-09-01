<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ContenidoTour;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ProveedorTipo;
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
            ->with(['proveedor', 'opcionesHotel.opcionesHotelTarifas', 'opcionales'])
            ->get();

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

    // Matriz hotel × tipo de habitación — mismo motor que paquetes_plantilla
    // (§2.4). El payload puede traer 'tarifas' anidado para crear el hotel
    // + su matriz de precios en un solo request.
    public function hoteles(Request $request, string $id)
    {
        $opcion = OpcionMayorista::findOrFail($id);

        if ($request->isMethod('get')) {
            $hoteles = OpcionHotel::where('opcion_mayorista_id', $opcion->id)->with('opcionesHotelTarifas')->get();

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
