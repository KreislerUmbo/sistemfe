<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
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
            // Retiro del catálogo activo (26-ago-2026) — una tarifa
            // desactivada a mano no debe ofrecerse para cotizaciones/
            // paquetes nuevos, aunque su rango de vigente_desde/hasta
            // siga siendo válido por fecha.
            ->where('activo', true)
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

        // Sesión 11l v2 — filtros de la biblioteca del cotizador (zona/
        // servicio/proveedor), combinables entre sí y con $search (AND).
        //
        // Fix (rama fix/biblioteca-filtro-zona-jerarquico): match exacto
        // dejaba sin resultados a un filtro por zona (ej. "Lamas") porque
        // casi ningún proveedor cuelga directo del nodo padre — ahora
        // incluye también todos sus descendientes (lugares/atractivos).
        $query->when($request->get('destino_atractivo_id'), function ($q, $destinoAtractivoId) {
            $ids = DestinoAtractivo::idsConDescendientes((int) $destinoAtractivoId);
            $q->whereHas('proveedorServicio.destinoServicio', fn ($qq) => $qq->whereIn('destino_atractivo_id', $ids));
        });

        $query->when($request->get('servicio_id'), fn ($q, $servicioId) => $q->whereHas(
            'proveedorServicio.destinoServicio',
            fn ($qq) => $qq->where('servicio_id', $servicioId)
        ));

        $query->when($request->get('proveedor_id'), fn ($q, $proveedorId) => $q->whereHas(
            'proveedorServicio',
            fn ($qq) => $qq->where('proveedor_id', $proveedorId)
        ));

        // Consolidación de hoteles — filtro opcional por tipo de habitación,
        // para buscar directamente "matrimonial"/"doble"/etc. entre todas
        // las tarifas de hotel de todos los proveedores, mismo patrón when()
        // que los demás filtros de acá arriba.
        $query->when($request->get('tipo_habitacion'), fn ($q, $th) => $q->where('tipo_habitacion', $th));

        // Antes: ->limit(100)->get() — sin total ni forma de pedir más, así
        // que pasado el resultado 100 el usuario perdía resultados en
        // silencio, sin ningún aviso. paquetes/detalle.vue (picker de
        // "Servicio de proveedor" en Incluye) es el único consumidor de
        // este endpoint (confirmado por grep en el frontend) — paginación
        // real acá no afecta a ninguna otra pantalla.
        $perPage = min((int) $request->input('per_page', 30), 100);

        $paginado = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'proveedor_tarifas' => $paginado->items(),
            'total' => $paginado->total(),
            'per_page' => $perPage,
            'current_page' => $paginado->currentPage(),
            'last_page' => $paginado->lastPage(),
        ]);
    }

    public function store(Request $request, string $proveedorServicioId)
    {
        $proveedorServicio = ProveedorServicio::with('proveedor')->findOrFail($proveedorServicioId);

        $validado = $this->validarPayload($request, $proveedorServicio);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $validado['proveedor_servicio_id'] = $proveedorServicio->id;
        // Explícito, no confiar en el default de BD: Model::create() no
        // refresca el modelo en memoria después del insert, así que sin
        // esto el JSON de respuesta devolvía "activo": null (el atributo ni
        // siquiera existe en memoria) en vez de true — no se notaba en la
        // UI porque el frontend recarga la lista completa después de
        // guardar, pero es un dato incorrecto igual.
        $validado['activo'] = true;
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
            // La nueva versión hereda el estado activo/inactivo de la que
            // reemplaza — editar el precio de una tarifa desactivada no
            // debe reactivarla en silencio, eso requiere un activar()
            // explícito. Mismo motivo que en store(): sin esto quedaría
            // null en vez de heredar el valor real.
            $validado['activo'] = $tarifaActual->activo;
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

    // Delete real, bloqueado si la tarifa ya quedó referenciada (precio
    // congelado) en algún registro histórico de cotización/reserva/
    // plantilla de tour — para ese caso existe desactivar()/activar()
    // (26-ago-2026), que retira del catálogo activo sin tocar el
    // historial.
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
                'message' => "No se puede eliminar: esta tarifa está en uso en {$totalUsos} cotización(es)/reserva(s)/plantilla(s) de tour. El precio ya quedó congelado en esos registros (no se ven afectados) — para retirarla del catálogo activo sin borrar el historial, desactivala en su lugar.",
            ], 422);
        }

        $tarifa->delete();

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa eliminada correctamente',
        ]);
    }

    // Retira la tarifa del catálogo activo (26-ago-2026) — deja de
    // ofrecerse en biblioteca() y no se puede elegir para ítems nuevos
    // (AlternativaItemController::store()), pero no toca ningún registro
    // histórico: el precio ya congelado en cotizaciones/reservas/plantillas
    // de tour que ya la usaron no se ve afectado. Sin guard de uso — a
    // diferencia de borrar, desactivar nunca rompe nada, así que no hace
    // falta la confirmación extra que sí tiene PaquetePlantillaController
    // para 'componente inactivo' de un combo.
    public function desactivar(string $id)
    {
        $tarifa = ProveedorTarifa::findOrFail($id);
        $tarifa->update(['activo' => false]);

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa desactivada — ya no aparece para cotizaciones/paquetes nuevos.',
            'proveedor_tarifa' => $tarifa,
        ]);
    }

    public function activar(string $id)
    {
        $tarifa = ProveedorTarifa::findOrFail($id);
        $tarifa->update(['activo' => true]);

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa reactivada — vuelve a estar disponible para cotizaciones/paquetes nuevos.',
            'proveedor_tarifa' => $tarifa,
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
            'descripcion' => 'nullable|string|max:250',
            'regimen_comida' => 'nullable|in:solo_alojamiento,desayuno,media_pension,pension_completa',
            'tipo_cama' => 'nullable|string|max:100',
            'precio_costo_cama_adicional' => 'nullable|numeric|min:0',
            'precio_venta_cama_adicional' => 'nullable|numeric|min:0',
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
            $validado['regimen_comida'] = null;
            $validado['tipo_cama'] = null;
            $validado['precio_costo_cama_adicional'] = null;
            $validado['precio_venta_cama_adicional'] = null;
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
