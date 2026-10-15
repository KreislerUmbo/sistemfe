<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\ProveedorTarifa;
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
//
// Fila 13, fase 1a (plan-tours-venta-simple-costo-real.md §3.2): cuarta
// forma, solo en un tour simple — el servicio sin tarifa
// (destino_servicio_id, costo escrito a mano). Cada ítem suma costo estimado
// por persona, IGV propio y proveedor sugerido (editables con update()).
// desdePlantilla() (cotizador) sigue igual: salta los ítems sin tarifa.
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
                'destinoServicio.servicio',
                'destinoServicio.destinoAtractivo',
                'proveedor',
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
            'destino_servicio_id' => 'nullable|integer|exists:destino_servicio,id',
            'orden' => 'nullable|integer|min:0',
        ] + $this->reglasCostoIgv());

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $soloServicio = ! empty($validado['destino_servicio_id'])
            && empty($validado['proveedor_tarifa_id'])
            && empty($validado['guia_tarifa_id'])
            && empty($validado['paquete_plantilla_hijo_id']);

        if ($soloServicio) {
            if (! $paquete->esTourSimple()) {
                return response()->json(['code' => 422, 'message' => 'Un servicio sin tarifa solo se puede agregar a un tour simple.'], 422);
            }

            $yaExiste = $paquete->items()
                ->where('destino_servicio_id', $validado['destino_servicio_id'])
                ->whereNull('proveedor_tarifa_id')
                ->exists();

            if ($yaExiste) {
                return response()->json(['code' => 422, 'message' => 'Este servicio ya está incluido en este tour/paquete.'], 422);
            }
        } else {
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

            // Con tarifa de proveedor, el servicio y el proveedor sugerido
            // salen de la tarifa (mismo dato que el backfill de la migración).
            if (! empty($validado['proveedor_tarifa_id'])) {
                $tarifa = ProveedorTarifa::with('proveedorServicio')->find($validado['proveedor_tarifa_id']);
                $validado['destino_servicio_id'] = $tarifa->proveedorServicio->destino_servicio_id;
                $validado['proveedor_id'] = $validado['proveedor_id'] ?? $tarifa->proveedorServicio->proveedor_id;
            } else {
                unset($validado['destino_servicio_id']);
            }
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

        $item->load([
            'proveedorTarifa.proveedorServicio.proveedor',
            'guiaTarifa.guia',
            'paquetePlantillaHijo',
            'destinoServicio.servicio',
            'proveedor',
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Ítem agregado al paquete correctamente',
            'paquete_plantilla_item' => $item,
        ]);
    }

    // PUT paquete-plantilla-items/{id} — fila 13, fase 1a: costo estimado,
    // IGV y proveedor sugerido de un servicio del Incluye. La tarifa de
    // referencia NO se cambia acá (define lo que hoy carga el cotizador en
    // modo clásico): para eso, quitar y volver a agregar, como siempre.
    public function update(Request $request, string $id)
    {
        $item = PaquetePlantillaItem::findOrFail($id);

        if ($item->paquete_plantilla_hijo_id !== null) {
            return response()->json(['code' => 422, 'message' => 'El costo y el IGV de un tour dentro de un combo se editan en el propio tour.'], 422);
        }

        $validator = Validator::make($request->all(), $this->reglasCostoIgv());
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $item->update($validator->validated());
        $item->load(['proveedorTarifa.proveedorServicio.proveedor', 'guiaTarifa.guia', 'destinoServicio.servicio', 'proveedor']);

        return response()->json([
            'code' => 200,
            'message' => 'Servicio del Incluye actualizado correctamente',
            'paquete_plantilla_item' => $item,
        ]);
    }

    public function destroy(string $id)
    {
        $item = PaquetePlantillaItem::findOrFail($id);
        $item->delete();

        return response()->json(['code' => 200, 'message' => 'Ítem quitado del paquete correctamente']);
    }

    private function reglasCostoIgv(): array
    {
        return [
            'proveedor_id' => 'nullable|integer|exists:proveedores,id',
            'costo_estimado_persona' => 'nullable|numeric|min:0',
            'costo_estimado_persona_ext' => 'nullable|numeric|min:0',
            'tip_afe_igv' => 'nullable|in:10,17,20,30,40',
            'destino_tributario' => 'nullable|required_with:tip_afe_igv|in:amazonia,nacional,extranjero',
        ];
    }
}
