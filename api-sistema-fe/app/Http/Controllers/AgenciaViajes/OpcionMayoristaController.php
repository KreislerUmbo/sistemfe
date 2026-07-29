<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTipo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $proveedor = Proveedor::findOrFail($validado['proveedor_id']);
        $esMayorista = $proveedor->tipo_id && ProveedorTipo::where('id', $proveedor->tipo_id)->where('slug', 'mayorista')->exists();

        if (! $esMayorista) {
            return response()->json(['code' => 422, 'message' => 'El proveedor seleccionado no es de tipo Mayorista.'], 422);
        }

        $opcion = OpcionMayorista::create($validado + [
            'alternativa_id' => $alternativa->id,
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
            'tarifas.*.tipo_habitacion' => 'required_with:tarifas|in:matrimonial,doble,triple,familiar',
            'tarifas.*.precio_costo' => 'required_with:tarifas|numeric|min:0',
            'tarifas.*.precio_venta' => 'required_with:tarifas|numeric|min:0',
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
            ]);

            foreach ($validado['tarifas'] ?? [] as $tarifa) {
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
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $opcional = OpcionMayoristaOpcional::create($validator->validated() + ['opcion_mayorista_id' => $opcion->id]);

        return response()->json(['code' => 200, 'message' => 'Opcional agregado correctamente', 'opcion_mayorista_opcional' => $opcional]);
    }
}
