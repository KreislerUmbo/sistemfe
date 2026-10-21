<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Services\AgenciaViajes\ConversionToursService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// Conversión de los tours existentes al modelo nuevo — fila 13, fase 1c
// (plan-tours-venta-simple-costo-real.md §3.11). Ver ConversionToursService:
// listar y ver la propuesta no escriben nada; solo confirmar() guarda.
class ConversionToursController extends Controller
{
    public function __construct(private ConversionToursService $conversion)
    {
    }

    // GET tours-conversion
    public function index()
    {
        return response()->json(['code' => 200] + $this->conversion->listar());
    }

    // GET tours-conversion/{id}
    public function show(string $id)
    {
        try {
            return response()->json(['code' => 200, 'propuesta' => $this->conversion->propuesta(PaquetePlantilla::findOrFail($id))]);
        } catch (ValidationException $e) {
            return response()->json(['code' => 422, 'message' => collect($e->errors())->flatten()->first()], 422);
        }
    }

    // POST tours-conversion/{id}/confirmar
    public function confirmar(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'precio_venta_final' => 'required|numeric|gt:0',
            'costo_estimado_persona' => 'nullable|numeric|min:0',
            'items' => 'nullable|array',
            'items.*.id' => 'required|integer',
            'items.*.costo_estimado_persona' => 'nullable|numeric|min:0',
            'items.*.tip_afe_igv' => 'nullable|in:10,17,20,30,40',
            'items.*.destino_tributario' => 'nullable|required_with:items.*.tip_afe_igv|in:amazonia,nacional,extranjero',
        ], [
            'precio_venta_final.required' => 'Escribe el precio por persona del tour.',
            'precio_venta_final.gt' => 'El precio por persona debe ser mayor a 0.',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        try {
            $tour = $this->conversion->confirmar(
                PaquetePlantilla::findOrFail($id),
                $validator->validated(),
                auth('api')->id()
            );
        } catch (ValidationException $e) {
            return response()->json(['code' => 422, 'message' => collect($e->errors())->flatten()->first()], 422);
        }

        return response()->json([
            'code' => 200,
            'message' => "Conversión de «{$tour->nombre}» confirmada.",
            'propuesta' => $this->conversion->propuesta($tour),
        ]);
    }
}
