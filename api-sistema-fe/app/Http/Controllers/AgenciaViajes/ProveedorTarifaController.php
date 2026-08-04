<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\Temporada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// plan-modulo-proveedores.md §2.6 / plan-modulo-cotizaciones-reservas.md
// §2.2, §2.3. Anidada bajo proveedor_servicio, salvo update() que cuelga
// directo de proveedor-tarifas/{id} (la tarifa ya identifica su propio
// padre, no hace falta repetir el proveedor_servicio_id en la URL).
class ProveedorTarifaController extends Controller
{
    public function index(string $proveedorServicioId)
    {
        $proveedorServicio = ProveedorServicio::findOrFail($proveedorServicioId);

        $tarifas = $proveedorServicio->proveedorTarifas()->orderByDesc('vigente_desde')->get();

        return response()->json(['proveedor_tarifas' => $tarifas]);
    }

    // "Biblioteca" del cotizador (Sesión 11b, §7.1) — tarifas vigentes de
    // TODOS los proveedores, con búsqueda por proveedor/servicio.
    //
    // Simplificación conocida, no resuelta en esta sesión: el plan original
    // decía "filtradas por destino_servicio de la cotización", pero
    // cotizaciones.destino es texto libre (§3.1, nunca fue FK a
    // destinos_atractivos) — no hay forma de filtrar por destino a nivel de
    // query sin cambiar ese schema, fuera de alcance acá. La biblioteca
    // lista TODO el catálogo activo con búsqueda de texto en su lugar.
    public function biblioteca(Request $request)
    {
        $hoy = now()->toDateString();
        $search = $request->get('search');

        $query = ProveedorTarifa::with([
            'proveedorServicio.proveedor',
            'proveedorServicio.destinoServicio.destinoAtractivo',
            'proveedorServicio.destinoServicio.servicio',
        ])
            ->where('vigente_desde', '<=', $hoy)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $hoy))
            // Sesión 11k, Fix 8 — un proveedor dado de baja no debe seguir
            // ofreciéndose como resultado nuevo en la biblioteca, aunque sus
            // tarifas sigan vigentes por fecha.
            ->whereHas('proveedorServicio.proveedor', fn ($q) => $q->where('estado', true));

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('proveedorServicio.proveedor', fn ($qq) => $qq->where('razon_social', 'ilike', "%{$search}%")->orWhere('nombre_comercial', 'ilike', "%{$search}%"))
                    ->orWhereHas('proveedorServicio.destinoServicio.servicio', fn ($qq) => $qq->where('nombre', 'ilike', "%{$search}%"));
            });
        }

        return response()->json(['proveedor_tarifas' => $query->orderByDesc('id')->limit(100)->get()]);
    }

    public function store(Request $request, string $proveedorServicioId)
    {
        $proveedorServicio = ProveedorServicio::with('proveedor')->findOrFail($proveedorServicioId);

        $validado = $this->validarPayload($request, $proveedorServicio);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $validado['proveedor_servicio_id'] = $proveedorServicio->id;
        $tarifa = ProveedorTarifa::create($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa registrada correctamente',
            'proveedor_tarifa' => $tarifa,
        ]);
    }

    // Versionado (§2.2): "nunca se sobrescribe un precio ya usado en una
    // cotización". Si la tarifa ya aparece en alternativa_items, se cierra
    // vigente_hasta=hoy sobre la fila actual y se crea una nueva versión con
    // los datos editados. Si nunca se usó, UPDATE directo — no ensucia el
    // historial con correcciones de tipeo de una tarifa recién cargada.
    public function update(Request $request, string $id)
    {
        $tarifaActual = ProveedorTarifa::with('proveedorServicio.proveedor')->findOrFail($id);

        $validado = $this->validarPayload($request, $tarifaActual->proveedorServicio);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $tieneUso = AlternativaItem::where('proveedor_tarifa_id', $tarifaActual->id)->exists();

        if ($tieneUso) {
            $tarifaActual->update(['vigente_hasta' => now()->toDateString()]);

            $validado['proveedor_servicio_id'] = $tarifaActual->proveedor_servicio_id;
            $validado['vigente_desde'] = $validado['vigente_desde'] ?? now()->toDateString();
            $nueva = ProveedorTarifa::create($validado);

            return response()->json([
                'code' => 200,
                'message' => 'Esta tarifa ya se usó en una cotización — se cerró la versión anterior y se creó una nueva.',
                'proveedor_tarifa' => $nueva,
                'version_anterior_id' => $tarifaActual->id,
            ]);
        }

        $tarifaActual->update($validado);

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa actualizada correctamente',
            'proveedor_tarifa' => $tarifaActual,
        ]);
    }

    // Sin soft delete ni columna 'activo' en esta sesión — delete real,
    // bloqueado si la tarifa ya quedó referenciada (precio congelado) en
    // algún registro histórico de cotización/reserva/plantilla de tour.
    public function destroy(string $id)
    {
        $tarifa = ProveedorTarifa::findOrFail($id);

        $enCotizaciones = AlternativaItem::where('proveedor_tarifa_id', $tarifa->id)->count();
        $enReservas = ReservaItem::where('proveedor_tarifa_id', $tarifa->id)->count();
        $enPlantillas = PaquetePlantillaItem::where('proveedor_tarifa_id', $tarifa->id)->count();
        $totalUsos = $enCotizaciones + $enReservas + $enPlantillas;

        if ($totalUsos > 0) {
            return response()->json([
                'code' => 422,
                'message' => "No se puede eliminar: esta tarifa está en uso en {$totalUsos} cotización(es)/reserva(s)/plantilla(s) de tour. El precio ya quedó congelado en esos registros (no se ven afectados), pero para retirar esta tarifa del catálogo activo hablalo con Umbo — por ahora no hay forma de desactivarla sin borrar el historial.",
            ], 422);
        }

        $tarifa->delete();

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa eliminada correctamente',
        ]);
    }

    private function validarPayload(Request $request, ProveedorServicio $proveedorServicio): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tipo_tarifa' => 'required|in:corporativa,grupal,publica',
            'modalidad' => 'required|in:compartido,privado',
            'moneda' => 'required|in:PEN,USD',
            'diferenciador' => 'nullable|array',
            'tipo_habitacion' => 'nullable|in:simple,matrimonial,doble,triple,familiar',
            'precio_costo' => 'required|numeric|min:0',
            'margen_tipo' => 'required|in:porcentaje,fijo',
            'margen_valor' => 'required|numeric|min:0',
            'descuento_maximo_pct' => 'nullable|numeric|min:0|max:100',
            'margen_minimo_pct' => 'nullable|numeric|min:0|max:100',
            'precio_venta_adulto' => 'required|numeric|min:0',
            'precio_venta_nino' => 'nullable|numeric|min:0',
            'precio_venta_infante' => 'nullable|numeric|min:0',
            'edad_min_nino' => 'nullable|integer|min:0',
            'edad_max_nino' => 'nullable|integer|min:0',
            'edad_max_infante' => 'nullable|integer|min:0',
            'temporada_id' => 'nullable|integer',
            'vigente_desde' => 'required|date',
            'vigente_hasta' => 'nullable|date|after_or_equal:vigente_desde',
            'tip_afe_igv' => 'required|string|max:2',
            'destino_tributario' => 'required|in:amazonia,nacional,extranjero',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $esHotel = $this->proveedorEsHotel($proveedorServicio);

        if ($esHotel && empty($validado['tipo_habitacion'])) {
            return response()->json([
                'code' => 422,
                'message' => 'tipo_habitacion es obligatorio para tarifas de proveedores tipo Hotel.',
            ], 422);
        }

        if (! $esHotel) {
            $validado['tipo_habitacion'] = null;
        }

        if (! empty($validado['temporada_id'])) {
            $existeTemporada = Temporada::where('id', $validado['temporada_id'])
                ->where('giro', 'agencia_viajes')
                ->exists();

            if (! $existeTemporada) {
                return response()->json(['code' => 422, 'message' => 'La temporada seleccionada no existe.'], 422);
            }
        }

        return $validado;
    }

    // tipo_id en Proveedor referencia proveedor_tipos (central) — sin FK real
    // cross-DB, se resuelve consultando el catálogo central directo.
    private function proveedorEsHotel(ProveedorServicio $proveedorServicio): bool
    {
        $proveedor = $proveedorServicio->relationLoaded('proveedor')
            ? $proveedorServicio->proveedor
            : $proveedorServicio->load('proveedor')->proveedor;

        if (! $proveedor || ! $proveedor->tipo_id) {
            return false;
        }

        return ProveedorTipo::where('id', $proveedor->tipo_id)->where('slug', 'alojamiento-hoteles')->exists();
    }
}
