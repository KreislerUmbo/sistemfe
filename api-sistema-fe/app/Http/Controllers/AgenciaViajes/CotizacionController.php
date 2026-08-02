<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\Client\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Header de cotización — plan-modulo-cotizaciones-reservas.md §3.1. El
// código ({prefijo}-{año}-{correlativo}) lo genera el propio modelo
// Cotizacion (evento creating(), con lockForUpdate() real solo si el
// caller envuelve en transacción — por eso store() abre una).
class CotizacionController extends Controller
{
    public function index(Request $request)
    {
        $query = Cotizacion::with('cliente')->withCount('alternativas');

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'ilike', "%{$search}%")
                    ->orWhere('destino', 'ilike', "%{$search}%");
            });
        }

        // Cotizacion no tiene columna 'estado' propia (vive en cada
        // alternativa) — ?estado= filtra cotizaciones que tengan AL MENOS
        // una alternativa en ese estado, no un estado propio del header.
        if ($request->filled('estado')) {
            $estado = $request->get('estado');
            $query->whereHas('alternativas', fn ($q) => $q->where('estado', $estado));
        }

        $cotizaciones = $query->orderByDesc('id')->paginate(15);

        return response()->json([
            'total' => $cotizaciones->total(),
            'paginate' => 15,
            'cotizaciones' => $cotizaciones->items(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cliente_id' => 'required|integer|exists:clients,id',
            'codigo_prefijo' => 'required|string|max:50',
            'destino' => 'required|string|max:250',
            'fecha_viaje_desde' => 'nullable|date',
            'fecha_viaje_hasta' => 'nullable|date|after_or_equal:fecha_viaje_desde',
            'pasajeros' => 'required|array|min:1',
            'pasajeros.*.edad' => 'required|integer|min:0|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        [$edadMaxInfante, $edadMaxNino] = $this->umbralesEdad();

        $cotizacion = DB::transaction(function () use ($validado, $edadMaxInfante, $edadMaxNino) {
            $cotizacion = Cotizacion::create([
                'cliente_id' => $validado['cliente_id'],
                'codigo_prefijo' => $validado['codigo_prefijo'],
                'destino' => $validado['destino'],
                'fecha_viaje_desde' => $validado['fecha_viaje_desde'] ?? null,
                'fecha_viaje_hasta' => $validado['fecha_viaje_hasta'] ?? null,
            ]);

            foreach ($validado['pasajeros'] as $pax) {
                CotizacionPasajero::create([
                    'cotizacion_id' => $cotizacion->id,
                    'edad' => $pax['edad'],
                    'tipo_pax' => $this->derivarTipoPax((int) $pax['edad'], $edadMaxInfante, $edadMaxNino),
                ]);
            }

            return $cotizacion;
        });

        $cotizacion->load('pasajeros', 'cliente');

        return response()->json([
            'code' => 200,
            'message' => 'Cotización creada correctamente',
            'cotizacion' => $cotizacion,
        ]);
    }

    public function show(string $id)
    {
        $cotizacion = Cotizacion::with([
            'cliente',
            'pasajeros',
            // .proveedorServicio.proveedor/.destinoServicio.servicio: sin esto, el
            // frontend (etiquetaItem() en editar.vue) no tiene forma de mostrar el
            // nombre del proveedor ni la categoría del servicio de cada ítem — solo
            // llegaba el proveedor_tarifa "pelado" (sin su cadena de relaciones).
            'alternativas.items.proveedorTarifa.proveedorServicio.proveedor',
            'alternativas.items.proveedorTarifa.proveedorServicio.destinoServicio.servicio',
            'alternativas.items.opcionMayorista',
            'alternativas.items.cotizacionPasajeAereo',
            // Sesión 11b3 — nombre del tour de origen para el encabezado del
            // bloque agrupado en el lienzo día-por-día (§7.1).
            'alternativas.items.tourOrigen',
        ])->findOrFail($id);

        return response()->json(['cotizacion' => $cotizacion]);
    }

    // Corregir cliente/destino/fechas después de crear la cotización —
    // gap real señalado por el usuario (store()/actualizarPasajeros() eran
    // los únicos puntos de escritura del header hasta ahora, ninguno
    // tocaba estos 4 campos). Sin guard de estado: cliente_id/destino/
    // fechas son solo informativos para este vertical (no alimentan
    // ninguna regla de precio/impuesto de alternativa_items), así que
    // corregirlos no rompe nada aunque la cotización ya tenga
    // alternativas o incluso una reserva aceptada — mismo criterio de
    // simplicidad que actualizarPasajeros(), que tampoco bloquea por estado.
    public function update(Request $request, string $id)
    {
        $cotizacion = Cotizacion::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'cliente_id' => 'required|integer|exists:clients,id',
            'destino' => 'required|string|max:250',
            'fecha_viaje_desde' => 'nullable|date',
            'fecha_viaje_hasta' => 'nullable|date|after_or_equal:fecha_viaje_desde',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $cotizacion->update($validator->validated());
        $cotizacion->load('cliente');

        return response()->json([
            'code' => 200,
            'message' => 'Cotización actualizada correctamente',
            'cotizacion' => $cotizacion,
        ]);
    }

    public function actualizarPasajeros(Request $request, string $id)
    {
        $cotizacion = Cotizacion::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'pasajeros' => 'required|array|min:1',
            'pasajeros.*.edad' => 'required|integer|min:0|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        [$edadMaxInfante, $edadMaxNino] = $this->umbralesEdad();
        $pasajeros = $validator->validated()['pasajeros'];

        DB::transaction(function () use ($cotizacion, $pasajeros, $edadMaxInfante, $edadMaxNino) {
            CotizacionPasajero::where('cotizacion_id', $cotizacion->id)->delete();

            foreach ($pasajeros as $pax) {
                CotizacionPasajero::create([
                    'cotizacion_id' => $cotizacion->id,
                    'edad' => $pax['edad'],
                    'tipo_pax' => $this->derivarTipoPax((int) $pax['edad'], $edadMaxInfante, $edadMaxNino),
                ]);
            }
        });

        $cotizacion->load('pasajeros');

        return response()->json([
            'code' => 200,
            'message' => 'Pasajeros actualizados correctamente',
            'cotizacion' => $cotizacion,
        ]);
    }

    private function derivarTipoPax(int $edad, int $edadMaxInfante, int $edadMaxNino): string
    {
        if ($edad <= $edadMaxInfante) {
            return 'infante';
        }
        if ($edad <= $edadMaxNino) {
            return 'nino';
        }

        return 'adulto';
    }

    private function umbralesEdad(): array
    {
        $config = ConfiguracionAgencia::first();

        return [
            $config->edad_max_infante ?? 2,
            $config->edad_max_nino ?? 12,
        ];
    }
}
