<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// plan-modulo-cotizaciones-reservas.md §5.3 — versionado igual que
// proveedor_tarifas (vigente_desde/vigente_hasta), pero SIN piso de
// descuento (descuento_maximo_pct/margen_minimo_pct no aplican a guías,
// confirmado en el plan). Sin update() a propósito en esta sesión — el
// plan solo pide GET/POST anidado bajo guía.
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

        $tarifa = GuiaTarifa::create(array_merge($validator->validated(), ['guia_id' => $guia->id]));
        $tarifa->load('destino');

        return response()->json([
            'code' => 200,
            'message' => 'Tarifa de guía registrada correctamente',
            'guia_tarifa' => $tarifa,
        ]);
    }
}
