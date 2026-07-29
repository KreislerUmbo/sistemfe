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

    public function destroy(string $id)
    {
        $item = TourItinerarioItem::findOrFail($id);
        $item->delete();

        return response()->json(['code' => 200, 'message' => 'Paso de itinerario eliminado correctamente']);
    }
}
