<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Services\AgenciaViajes\ComboExplosionService;
use App\Services\AgenciaViajes\ComboValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// "Incluye" del paquete — plan-modulo-cotizaciones-reservas.md §3.7 ("cada
// ítem es un destino_servicio + proveedor_tarifa real, NO texto libre").
// Regla "uno de los tres entre proveedor_tarifa_id/guia_tarifa_id/
// paquete_plantilla_hijo_id, nunca más de uno ni ninguno" — extendida en
// Sesión 11b4 con la tercera opción (tour-hijo dentro de un paquete_combo),
// validada acá vía ComboValidationService.
class PaquetePlantillaItemController extends Controller
{
    public function __construct(
        private ComboValidationService $comboValidation,
        private ComboExplosionService $comboExplosion
    ) {
    }

    public function index(string $paqueteId)
    {
        $paquete = PaquetePlantilla::findOrFail($paqueteId);

        $items = $paquete->items()
            ->with([
                'proveedorTarifa.proveedorServicio.proveedor',
                'proveedorTarifa.proveedorServicio.destinoServicio.destinoAtractivo',
                'proveedorTarifa.proveedorServicio.destinoServicio.servicio',
                'guiaTarifa.guia',
                'paquetePlantillaHijo',
            ])
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
            'paquete_plantilla_hijo_id' => 'nullable|integer|exists:paquetes_plantilla,id',
            'orden' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $errorExclusividad = $this->comboValidation->validarExclusividadMutua(
            $validado['proveedor_tarifa_id'] ?? null,
            $validado['guia_tarifa_id'] ?? null,
            $validado['paquete_plantilla_hijo_id'] ?? null
        );

        if ($errorExclusividad !== null) {
            return response()->json(['code' => 422, 'message' => $errorExclusividad], 422);
        }

        $hijo = ! empty($validado['paquete_plantilla_hijo_id'])
            ? PaquetePlantilla::find($validado['paquete_plantilla_hijo_id'])
            : null;

        $errorProfundidad = $this->comboValidation->validarProfundidad($paquete, $hijo);
        if ($errorProfundidad !== null) {
            return response()->json(['code' => 422, 'message' => $errorProfundidad], 422);
        }

        $errorDuplicado = $this->comboValidation->validarNoDuplicado(
            $paquete,
            $validado['proveedor_tarifa_id'] ?? null,
            $validado['guia_tarifa_id'] ?? null,
            $validado['paquete_plantilla_hijo_id'] ?? null
        );

        if ($errorDuplicado !== null) {
            return response()->json(['code' => 422, 'message' => $errorDuplicado], 422);
        }

        try {
            $item = DB::transaction(function () use ($paquete, $validado) {
                $item = PaquetePlantillaItem::create($validado + ['paquete_plantilla_id' => $paquete->id]);

                // Piso de margen del combo (punto 3 del diseño): se valida
                // DESPUÉS de insertar (dentro de la misma transacción) para
                // reusar el cálculo real de ComboExplosionService en vez de
                // reimplementar la suma a mano — si no pasa, se revierte.
                if ($paquete->esPaqueteCombo() && $paquete->margen_minimo_pct !== null) {
                    $totales = $this->comboExplosion->totalesCombo($paquete->fresh());

                    $errorMargen = $this->comboValidation->validarMargenMinimo(
                        $totales['costo_total_combo'],
                        $totales['venta_neta_combo'],
                        (float) $paquete->margen_minimo_pct
                    );

                    if ($errorMargen !== null) {
                        throw new \RuntimeException($errorMargen);
                    }
                }

                return $item;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
        }

        $item->load(['proveedorTarifa.proveedorServicio.proveedor', 'guiaTarifa.guia', 'paquetePlantillaHijo']);

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
