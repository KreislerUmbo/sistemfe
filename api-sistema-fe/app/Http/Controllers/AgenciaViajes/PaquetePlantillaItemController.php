<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// "Incluye" del paquete — plan-modulo-cotizaciones-reservas.md §3.7 ("cada
// ítem es un destino_servicio + proveedor_tarifa real, NO texto libre").
// Regla "uno de los dos entre proveedor_tarifa_id/guia_tarifa_id, nunca
// ambos ni ninguno" quedó documentada como pendiente de validar a nivel de
// aplicación en la propia migración de Sesión 6 (sin CHECK de Postgres) —
// se resuelve acá, en store().
class PaquetePlantillaItemController extends Controller
{
    public function index(string $paqueteId)
    {
        $paquete = PaquetePlantilla::findOrFail($paqueteId);

        $items = $paquete->items()
            ->with(['proveedorTarifa.proveedorServicio.proveedor', 'guiaTarifa.guia'])
            ->orderBy('orden')
            ->get();

        return response()->json(['paquete_plantilla_items' => $items]);
    }

    public function store(Request $request, string $paqueteId)
    {
        $paquete = PaquetePlantilla::findOrFail($paqueteId);

        $validator = Validator::make($request->all(), [
            'proveedor_tarifa_id' => 'nullable|integer|exists:proveedor_tarifas,id',
            'guia_tarifa_id' => 'nullable|integer|exists:guia_tarifas,id',
            'orden' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $tieneProveedor = ! empty($validado['proveedor_tarifa_id']);
        $tieneGuia = ! empty($validado['guia_tarifa_id']);

        if ($tieneProveedor === $tieneGuia) {
            return response()->json([
                'code' => 422,
                'message' => 'Elegí exactamente uno: proveedor_tarifa_id o guia_tarifa_id, no ambos ni ninguno.',
            ], 422);
        }

        $item = PaquetePlantillaItem::create($validado + ['paquete_plantilla_id' => $paquete->id]);
        $item->load(['proveedorTarifa.proveedorServicio.proveedor', 'guiaTarifa.guia']);

        return response()->json([
            'code' => 200,
            'message' => 'Ítem agregado al paquete correctamente',
            'paquete_plantilla_item' => $item,
        ]);
    }

    public function destroy(string $id)
    {
        $item = PaquetePlantillaItem::findOrFail($id);
        $item->delete();

        return response()->json(['code' => 200, 'message' => 'Ítem quitado del paquete correctamente']);
    }
}
