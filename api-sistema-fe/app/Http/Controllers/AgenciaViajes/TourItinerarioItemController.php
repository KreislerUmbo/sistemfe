<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\TourItinerarioItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Itinerario día-por-día del tour — plan-modulo-tours-catalogo.md §5,
// ejemplos reales "Full Day Alto Mayo"/"Tours Lamas Nativo". orden
// secuencia actividades del mismo día_relativo cuando no hay hora exacta
// (mismo criterio que alternativa_items.cantidad — dato real encontrado
// al construir el prototipo, no todo paso trae hora).
class TourItinerarioItemController extends Controller
{
    public function index(string $paqueteId)
    {
        $paquete = PaquetePlantilla::findOrFail($paqueteId);

        $items = $paquete->paqueteItinerario()
            ->with('destinoAtractivo')
            ->orderBy('dia_relativo')
            ->orderBy('orden')
            ->get();

        return response()->json(['tour_itinerario_items' => $items]);
    }

    public function store(Request $request, string $paqueteId)
    {
        $paquete = PaquetePlantilla::findOrFail($paqueteId);

        $validator = Validator::make($request->all(), [
            'dia_relativo' => 'required|integer|min:1',
            'hora' => 'nullable|date_format:H:i',
            'orden' => 'nullable|integer|min:0',
            'destino_atractivo_id' => 'nullable|integer|exists:destinos_atractivos,id',
            'descripcion' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $item = TourItinerarioItem::create($validator->validated() + ['tour_id' => $paquete->id]);
        $item->load('destinoAtractivo');

        return response()->json([
            'code' => 200,
            'message' => 'Paso de itinerario agregado correctamente',
            'tour_itinerario_item' => $item,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $item = TourItinerarioItem::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'dia_relativo' => 'required|integer|min:1',
            'hora' => 'nullable|date_format:H:i',
            'orden' => 'nullable|integer|min:0',
            'destino_atractivo_id' => 'nullable|integer|exists:destinos_atractivos,id',
            'descripcion' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $item->update($validator->validated());
        $item->load('destinoAtractivo');

        return response()->json([
            'code' => 200,
            'message' => 'Paso de itinerario actualizado correctamente',
            'tour_itinerario_item' => $item,
        ]);
    }

    public function destroy(string $id)
    {
        $item = TourItinerarioItem::findOrFail($id);
        $item->delete();

        return response()->json(['code' => 200, 'message' => 'Paso de itinerario eliminado correctamente']);
    }

    // Drag&drop del itinerario (Sesión 11l) — recibe el array completo de
    // pasos afectados (mismo día de origen + mismo día de destino cuando el
    // paso cruza de día) y persiste dia_relativo/orden en una sola
    // transacción. Valida pertenencia a $paqueteId antes de tocar nada,
    // mismo criterio que el resto de la vertical (no confiar en IDs sueltos
    // del frontend sin validar ownership).
    public function reordenar(Request $request, string $paqueteId)
    {
        $paquete = PaquetePlantilla::findOrFail($paqueteId);

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer',
            'items.*.dia_relativo' => 'required|integer|min:1',
            'items.*.orden' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $items = $validator->validated()['items'];
        $ids = array_column($items, 'id');

        $pertenecientes = TourItinerarioItem::where('tour_id', $paquete->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count($pertenecientes) !== count($ids)) {
            return response()->json(['code' => 422, 'message' => 'Alguno de los pasos no pertenece a este paquete'], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                TourItinerarioItem::where('id', $item['id'])->update([
                    'dia_relativo' => $item['dia_relativo'],
                    'orden' => $item['orden'],
                ]);
            }
        });

        return response()->json(['code' => 200, 'message' => 'Itinerario reordenado correctamente']);
    }
}
