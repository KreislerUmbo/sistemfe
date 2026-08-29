<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// plan-modulo-cotizaciones-reservas.md §5.3 — versionado igual que
// proveedor_tarifas (vigente_desde/vigente_hasta), pero SIN piso de
// descuento (descuento_maximo_pct/margen_minimo_pct no aplican a guías,
// confirmado en el plan).
//
// update()/destroy()/desactivar()/activar() agregados 29-ago-2026 —
// mismo patrón ya probado en ProveedorTarifaController (commit 5347e66,
// 26-ago-2026): destroy() real bloqueado si la tarifa está en uso
// (alternativa_items/paquete_plantilla_items la referencian por FK, sin
// cascade), con desactivar()/activar() como alternativa reversible que
// nunca rompe nada. reserva_items NO referencia guia_tarifa_id (usa
// guia_id directo, asignación operativa posterior) — confirmado por
// grep, no hace falta chequearla acá.
class GuiaTarifaController extends Controller
{
    public function index(string $guiaId)
    {
        $guia = Guia::findOrFail($guiaId);

        $tarifas = $guia->guiaTarifas()->with('destino')->orderByDesc('vigente_desde')->get();

        return response()->json(['guia_tarifas' => $tarifas]);
    }

    public function store(Request $request, string $guiaId)
    {
        $guia = Guia::findOrFail($guiaId);

        $validado = $this->validarPayload($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        // Explícito, no confiar en el default de BD: Model::create() no
        // refresca el modelo en memoria después del insert, así que sin
        // esto el JSON de respuesta devolvía "activo": null en vez de
        // true — mismo fix colateral que ya se hizo en
        // ProveedorTarifaController::store().
        $validado['activo'] = true;
        $tarifa = GuiaTarifa::create(array_merge($validado, ['guia_id' => $guia->id]));
        $tarifa->load('destino');

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa de guía registrada correctamente',
            'guia_tarifa' => $tarifa,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $tarifaActual = GuiaTarifa::with('guia')->findOrFail($id);

        $validado = $this->validarPayload($request);
        if ($validado instanceof JsonResponse) {
            return $validado;
        }

        $tieneUso = AlternativaItem::where('guia_tarifa_id', $tarifaActual->id)->exists()
            || PaquetePlantillaItem::where('guia_tarifa_id', $tarifaActual->id)->exists();

        if ($tieneUso) {
            $tarifaActual->update(['vigente_hasta' => now()->toDateString()]);

            $validado['guia_id'] = $tarifaActual->guia_id;
            $validado['vigente_desde'] = $validado['vigente_desde'] ?? now()->toDateString();
            // La nueva versión hereda el estado activo/inactivo de la que
            // reemplaza — editar el precio de una tarifa desactivada no
            // debe reactivarla en silencio, eso requiere un activar()
            // explícito.
            $validado['activo'] = $tarifaActual->activo;
            $nueva = GuiaTarifa::create($validado);
            $nueva->load('destino');

            return response()->json([
                'code' => 200,
                'message' => 'Esta tarifa ya se usó en una cotización/plantilla — se cerró la versión anterior y se creó una nueva.',
                'guia_tarifa' => $nueva,
                'version_anterior_id' => $tarifaActual->id,
            ]);
        }

        $tarifaActual->update($validado);
        $tarifaActual->load('destino');

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa actualizada correctamente',
            'guia_tarifa' => $tarifaActual,
        ]);
    }

    // Delete real, bloqueado si la tarifa ya quedó referenciada (precio
    // congelado) en algún registro histórico de cotización/plantilla de
    // tour — para ese caso existe desactivar()/activar(), que retira del
    // catálogo activo sin tocar el historial.
    public function destroy(string $id)
    {
        $tarifa = GuiaTarifa::findOrFail($id);

        $enCotizaciones = AlternativaItem::where('guia_tarifa_id', $tarifa->id)->count();
        $enPlantillas = PaquetePlantillaItem::where('guia_tarifa_id', $tarifa->id)->count();
        $totalUsos = $enCotizaciones + $enPlantillas;

        if ($totalUsos > 0) {
            return response()->json([
                'code' => 422,
                'message' => "No se puede eliminar: esta tarifa está en uso en {$totalUsos} cotización(es)/plantilla(s) de tour. El precio ya quedó congelado en esos registros (no se ven afectados) — para retirarla del catálogo activo sin borrar el historial, desactivala en su lugar.",
            ], 422);
        }

        $tarifa->delete();

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa de guía eliminada correctamente',
        ]);
    }

    // Retira la tarifa del catálogo activo — deja de ofrecerse en el
    // picker de GuiaController::show() y no se puede elegir para ítems
    // nuevos (AlternativaItemController::crearItemGuia()), pero no toca
    // ningún registro histórico. Sin guard de uso — a diferencia de
    // borrar, desactivar nunca rompe nada.
    public function desactivar(string $id)
    {
        $tarifa = GuiaTarifa::findOrFail($id);
        $tarifa->update(['activo' => false]);

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa desactivada — ya no aparece para cotizaciones/paquetes nuevos.',
            'guia_tarifa' => $tarifa,
        ]);
    }

    public function activar(string $id)
    {
        $tarifa = GuiaTarifa::findOrFail($id);
        $tarifa->update(['activo' => true]);

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa reactivada — vuelve a estar disponible para cotizaciones/paquetes nuevos.',
            'guia_tarifa' => $tarifa,
        ]);
    }

    private function validarPayload(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'destino_id' => 'required|integer|exists:destinos_atractivos,id',
            'modalidad' => 'required|in:dia_local,grupo_multidia',
            'costo_diario' => 'required|numeric|min:0',
            'tipo_margen' => 'required|in:porcentaje,fijo',
            'margen_valor' => 'required|numeric|min:0',
            'moneda' => 'required|in:PEN,USD',
            'vigente_desde' => 'required|date',
            'vigente_hasta' => 'nullable|date|after_or_equal:vigente_desde',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        return $validator->validated();
    }
}
