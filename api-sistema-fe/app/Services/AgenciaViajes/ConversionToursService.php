<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Conversión de los tours existentes al modelo nuevo — fila 13, fase 1c
// (plan-tours-venta-simple-costo-real.md §3.11; brief
// PEGAR-EN-CLAUDE-CODE-13-fase1c-facturacion-conversion.md §2).
//
// Convertir = dejar el tour con lo que necesita para cargarse como una línea
// en la cotización (1b): precio por persona, costo estimado de cada servicio
// del Incluye y su IGV. Se hace tour por tour y SOLO con la confirmación del
// usuario (D20): propuesta() no escribe nada; confirmar() es lo único que
// guarda, con los valores que el usuario aceptó o corrigió.
//
// Fuera de la conversión: los combos (se arman con sus tours, que se
// convierten uno por uno) y los tours solo_itinerario (de mayorista, no se
// venden sueltos).
class ConversionToursService
{
    public function __construct(
        private ComboExplosionService $comboExplosion,
        private TourCostoEstimadoService $costoEstimado,
        private DiagnosticoToursService $diagnostico,
    ) {
    }

    /**
     * Tours simples de venta, con el diagnóstico de la 1a y si ya se confirmaron.
     *
     * @return array{tours: array<int, array<string, mixed>>, fuera: array{combos: int, solo_itinerario: int}}
     */
    public function listar(): array
    {
        $filas = collect($this->diagnostico->diagnosticar());
        $confirmados = PaquetePlantilla::whereNotNull('conversion_confirmada_en')
            ->pluck('conversion_confirmada_en', 'id');

        $tours = $filas
            ->filter(fn (array $f) => $f['tipo'] === PaquetePlantilla::TIPO_TOUR_SIMPLE
                && $f['uso_actual'] !== PaquetePlantilla::USO_SOLO_ITINERARIO)
            ->map(fn (array $f) => $f + ['conversion_confirmada_en' => $confirmados[$f['id']] ?? null])
            ->values()
            ->all();

        return [
            'tours' => $tours,
            'fuera' => [
                'combos' => $filas->where('tipo', PaquetePlantilla::TIPO_PAQUETE_COMBO)->count(),
                'solo_itinerario' => $filas->where('tipo', PaquetePlantilla::TIPO_TOUR_SIMPLE)
                    ->where('uso_actual', PaquetePlantilla::USO_SOLO_ITINERARIO)->count(),
            ],
        ];
    }

    /**
     * Propuesta de conversión de un tour. No escribe nada.
     *
     * @return array<string, mixed>
     */
    public function propuesta(PaquetePlantilla $tour): array
    {
        $this->exigirConvertible($tour);

        $default = ConfiguracionAgencia::tratamientoTributarioDefault();
        $igvTour = [
            'tip_afe_igv' => $tour->tip_afe_igv ?? $default['tip_afe_igv'],
            'destino_tributario' => $tour->destino_tributario ?? $default['destino_tributario'],
        ];

        $calculadora = collect($this->costoEstimado->calcular($tour)['items'])->keyBy('item_id');
        $items = $this->itemsDelIncluye($tour)->map(function (PaquetePlantillaItem $item) use ($calculadora) {
            $sugerido = $calculadora[$item->id] ?? null;
            $tarifa = $item->proveedorTarifa;

            // Hallazgo 3 de la 1a: se propone el IGV de la tarifa, no vacío
            // (vacío = hereda el del tour, que puede no ser el real).
            $igvTarifa = $tarifa?->tip_afe_igv !== null
                ? ['tip_afe_igv' => $tarifa->tip_afe_igv, 'destino_tributario' => $tarifa->destino_tributario ?? 'nacional']
                : null;
            $igvActual = $item->tip_afe_igv !== null
                ? ['tip_afe_igv' => $item->tip_afe_igv, 'destino_tributario' => $item->destino_tributario]
                : null;
            $costoActual = $item->costo_estimado_persona !== null ? (float) $item->costo_estimado_persona : null;

            return [
                'id' => $item->id,
                'servicio' => TourLineaService::nombreServicio($item),
                'proveedor' => $item->proveedor?->nombre_comercial ?? $item->proveedor?->razon_social,
                'costo_actual' => $costoActual,
                'costo_sugerido' => $sugerido['costo_sugerido'] ?? null,
                'detalle_sugerido' => $sugerido['detalle'] ?? null,
                'motivo_sin_sugerencia' => $sugerido['motivo'] ?? null,
                'costo_propuesto' => $costoActual ?? ($sugerido['costo_sugerido'] ?? null),
                'igv_actual' => $igvActual,
                'igv_tarifa' => $igvTarifa,
                'igv_propuesto' => $igvActual ?? $igvTarifa,
            ];
        })->values();

        $totales = $this->comboExplosion->totalesTour($tour);
        $precioCargado = $tour->precio_venta_final !== null ? (float) $tour->precio_venta_final : null;
        $ajuste = (float) ($tour->ajuste_redondeo ?? 0);
        $precioPropuesto = $precioCargado
            ?? ($totales['venta_total'] > 0 ? round($totales['venta_total'] + $ajuste, 2) : null);

        $propuesta = [
            'id' => $tour->id,
            'codigo' => $tour->codigo,
            'nombre' => $tour->nombre,
            'moneda' => $tour->moneda ?? 'PEN',
            'igv_tour' => $igvTour,
            'igv_tour_es_default' => $tour->tip_afe_igv === null,
            'precio_cargado' => $precioCargado,
            'precio_propuesto' => $precioPropuesto,
            'precio_origen' => $precioCargado !== null ? 'cargado' : ($precioPropuesto !== null ? 'tarifas' : null),
            'suma_venta_tarifas' => $totales['venta_total'],
            'ajuste_redondeo' => $ajuste != 0.0 ? $ajuste : null,
            'costo_tour_actual' => $tour->costo_estimado_persona !== null ? (float) $tour->costo_estimado_persona : null,
            'items' => $items->all(),
            'conversion_confirmada_en' => $tour->conversion_confirmada_en,
        ];

        $propuesta['avisos'] = $this->avisos($propuesta);

        return $propuesta;
    }

    /**
     * Guarda la conversión con los valores que el usuario confirmó.
     *
     * @param  array{precio_venta_final: float|string, costo_estimado_persona?: float|string|null, items?: array<int, array{id: int, costo_estimado_persona: float|string|null, tip_afe_igv: ?string, destino_tributario: ?string}>}  $datos
     */
    public function confirmar(PaquetePlantilla $tour, array $datos, ?int $usuarioId): PaquetePlantilla
    {
        $this->exigirConvertible($tour);

        $itemsDelTour = $this->itemsDelIncluye($tour)->keyBy('id');
        $cambios = collect($datos['items'] ?? [])->keyBy('id');

        $ajenos = $cambios->keys()->diff($itemsDelTour->keys());
        if ($ajenos->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'Hay servicios que no pertenecen al Incluye de este tour: '.$ajenos->implode(', ')]);
        }

        // El IGV final de cada servicio (vacío = el del tour) decide si el
        // tour mezcla: en ese caso todos los servicios necesitan costo para
        // repartir el precio entre las líneas (si no, la carga da 422).
        $default = ConfiguracionAgencia::tratamientoTributarioDefault();
        $igvTour = ($tour->tip_afe_igv ?? $default['tip_afe_igv']).'|'.($tour->destino_tributario ?? $default['destino_tributario']);
        $finales = $itemsDelTour->map(function (PaquetePlantillaItem $item) use ($cambios, $igvTour) {
            $cambio = $cambios->get($item->id);
            $tip = $cambio ? ($cambio['tip_afe_igv'] ?? null) : $item->tip_afe_igv;
            $destino = $cambio ? ($cambio['destino_tributario'] ?? null) : $item->destino_tributario;
            $costo = $cambio ? ($cambio['costo_estimado_persona'] ?? null) : $item->costo_estimado_persona;

            return [
                'item' => $item,
                'tip_afe_igv' => $tip,
                'destino_tributario' => $tip !== null ? $destino : null,
                'costo' => $costo !== null ? (float) $costo : null,
                'grupo' => $tip !== null ? $tip.'|'.$destino : $igvTour,
            ];
        });

        if ($finales->pluck('grupo')->unique()->count() > 1) {
            $sinCosto = $finales->filter(fn (array $f) => $f['costo'] === null);
            if ($sinCosto->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => 'El tour mezcla tipos de IGV: todos los servicios necesitan costo para repartir el precio. Falta en: '
                    .$sinCosto->map(fn (array $f) => TourLineaService::nombreServicio($f['item']))->implode(', ').'.']);
            }
        }

        DB::transaction(function () use ($tour, $datos, $finales, $cambios, $usuarioId) {
            foreach ($finales as $final) {
                if (! $cambios->has($final['item']->id)) {
                    continue;
                }
                $final['item']->update([
                    'costo_estimado_persona' => $final['costo'],
                    'tip_afe_igv' => $final['tip_afe_igv'],
                    'destino_tributario' => $final['destino_tributario'],
                ]);
            }

            $tour->update([
                'precio_venta_final' => $datos['precio_venta_final'],
                'costo_estimado_persona' => array_key_exists('costo_estimado_persona', $datos)
                    ? $datos['costo_estimado_persona']
                    : $tour->costo_estimado_persona,
                'conversion_confirmada_en' => now(),
                'conversion_confirmada_por' => $usuarioId,
            ]);
        });

        return $tour->fresh();
    }

    private function exigirConvertible(PaquetePlantilla $tour): void
    {
        if (! $tour->esTourSimple()) {
            throw ValidationException::withMessages(['tour' => 'Solo se convierten tours simples: un combo se arma con sus tours, que se convierten uno por uno.']);
        }
        if ($tour->esSoloItinerario()) {
            throw ValidationException::withMessages(['tour' => 'Este tour es solo de itinerario (de mayorista): no se vende suelto y queda fuera de la conversión.']);
        }
    }

    private function itemsDelIncluye(PaquetePlantilla $tour)
    {
        return $tour->items()
            ->whereNull('paquete_plantilla_hijo_id')
            ->with(['proveedor', 'destinoServicio.servicio', 'proveedorTarifa.proveedorServicio.destinoServicio.servicio', 'guiaTarifa.guia'])
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function avisos(array $p): array
    {
        $avisos = [];

        if ($p['precio_propuesto'] === null) {
            $avisos[] = 'Sin precio ni tarifas con venta: escribe el precio por persona.';
        } elseif ($p['precio_origen'] === 'tarifas') {
            $avisos[] = $p['ajuste_redondeo'] !== null
                ? 'Sin precio cargado: se propone la suma de ventas de sus tarifas más el ajuste de redondeo (lo que hoy cobra el modo clásico).'
                : 'Sin precio cargado: se propone la suma de ventas de sus tarifas.';
        }

        if ($p['items'] === []) {
            $avisos[] = 'El tour no tiene servicios en el Incluye: el costo estimado sale del escrito en el tour.';
        }

        $igvFinal = collect($p['items'])->map(fn (array $i) => $i['igv_propuesto'] ?? $p['igv_tour'])
            ->map(fn (array $igv) => $igv['tip_afe_igv'].'|'.$igv['destino_tributario'])
            ->unique();
        $sinCosto = collect($p['items'])->whereNull('costo_propuesto');

        if ($igvFinal->count() > 1) {
            $avisos[] = 'Mezcla tipos de IGV: se cargará como una línea por tipo de IGV, y todos los servicios necesitan costo.';
        }
        if ($sinCosto->isNotEmpty()) {
            $avisos[] = $sinCosto->count().' servicio(s) sin costo: la línea del tour se cargará con costo incompleto.';
        }

        foreach ($p['items'] as $item) {
            if ($item['igv_actual'] !== null && $item['igv_tarifa'] !== null && $item['igv_actual'] != $item['igv_tarifa']) {
                $avisos[] = "«{$item['servicio']}»: el IGV guardado no coincide con el de su tarifa; revísalo.";
            }
        }

        return $avisos;
    }
}
