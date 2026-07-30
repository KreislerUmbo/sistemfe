<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Ítem de reserva copiado de la alternativa aceptada —
// plan-modulo-cotizaciones-reservas.md §4. Costo/precio se leen vía
// alternativaItem(), esta tabla solo guarda lo operativo (guía, proveedor
// que finalmente opera, fecha/hora concretas del servicio).
class ReservaItemController extends Controller
{
    public function update(Request $request, string $id)
    {
        $item = ReservaItem::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'guia_id' => 'nullable|integer|exists:guias,id',
            'proveedor_tarifa_id' => 'nullable|integer|exists:proveedor_tarifas,id',
            'fecha' => 'nullable|date',
            'hora' => 'nullable|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        // A diferencia de AlternativaController::update() (que descarta
        // valores null con array_filter para no pisar campos no enviados),
        // acá SÍ hace falta que un null explícito persista — guia_id/
        // proveedor_tarifa_id/fecha/hora deben poder "vaciarse" sin
        // bloquear nada (§4.2: el guía se asigna un día antes, puede quedar
        // pendiente). validated() ya solo trae las claves realmente
        // enviadas en el request, así que un campo omitido no se toca.
        $item->update($validator->validated());

        return response()->json([
            'code' => 200,
            'message' => 'Ítem actualizado correctamente',
            'reserva_item' => $item->fresh(['alternativaItem', 'guia', 'proveedorTarifa']),
        ]);
    }
}
