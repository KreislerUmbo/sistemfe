<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\PaquetePlantilla;
use Illuminate\Support\Facades\DB;

// Diagnóstico de los tours existentes — fila 13, fase 1a
// (plan-tours-venta-simple-costo-real.md §3.11, N15). SOLO LECTURA: arma, tour
// por tour, la propuesta de conversión al modelo nuevo para que el usuario la
// decida en la sesión 1b (la conversión real NO es de esta fase). Corre sobre
// la conexión del tenant ya inicializado; lo usa
// `agencia-viajes:diagnosticar-tours`.
class DiagnosticoToursService
{
    public function __construct(
        private ComboExplosionService $comboExplosion,
        private TourCostoEstimadoService $costoEstimado,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function diagnosticar(): array
    {
        $vinculos = DB::table('opcion_mayorista_tours')
            ->whereNotNull('paquete_plantilla_id')
            ->selectRaw('paquete_plantilla_id, count(*) n')
            ->groupBy('paquete_plantilla_id')
            ->pluck('n', 'paquete_plantilla_id');

        $tours = PaquetePlantilla::with(['items.proveedorTarifa', 'items.guiaTarifa'])
            ->orderBy('tipo')
            ->orderBy('id')
            ->get();

        return $tours->map(function (PaquetePlantilla $tour) use ($vinculos) {
            $vinculadoMayoristas = (int) ($vinculos[$tour->id] ?? 0);
            $usoPropuesto = $vinculadoMayoristas > 0 && $tour->precio_venta_final === null
                ? PaquetePlantilla::USO_SOLO_ITINERARIO
                : PaquetePlantilla::USO_VENTA;

            $items = $tour->items->whereNull('paquete_plantilla_hijo_id');
            $tiposIgv = $items->map(fn ($i) => $i->tip_afe_igv ?? $i->proveedorTarifa?->tip_afe_igv)
                ->filter()->unique()->values();

            $fila = [
                'id' => $tour->id,
                'codigo' => $tour->codigo,
                'nombre' => $tour->nombre,
                'tipo' => $tour->tipo,
                'activo' => (bool) $tour->activo,
                'uso_actual' => $tour->uso,
                'uso_propuesto' => $usoPropuesto,
                'vinculado_mayoristas' => $vinculadoMayoristas,
                'precio_cargado' => $tour->precio_venta_final !== null ? (float) $tour->precio_venta_final : null,
                'ajuste_redondeo' => $tour->ajuste_redondeo !== null ? (float) $tour->ajuste_redondeo : null,
                'servicios' => $items->count(),
                'igv_tipos' => $tiposIgv->implode('/'),
                'igv_mezclado' => $tiposIgv->count() > 1,
                'tiene_guia' => $items->whereNotNull('guia_tarifa_id')->isNotEmpty(),
                'tiene_privados' => $items->contains(fn ($i) => $i->proveedorTarifa?->modalidad === 'privado'),
                'suma_costo_tarifas' => null,
                'suma_venta_tarifas' => null,
                'precio_propuesto' => null,
                'costo_propuesto' => null,
                'avisos' => [],
            ];

            if ($tour->esPaqueteCombo()) {
                $fila['avisos'][] = 'Combo: se convierte en una línea por tour (sesión 1b).';

                return $fila;
            }

            $totales = $this->comboExplosion->totalesTour($tour);
            $fila['suma_costo_tarifas'] = $totales['costo_total'];
            $fila['suma_venta_tarifas'] = $totales['venta_total'];

            if ($usoPropuesto === PaquetePlantilla::USO_SOLO_ITINERARIO) {
                $fila['avisos'][] = 'Solo itinerario de mayorista: fuera de la conversión.';

                return $fila;
            }

            $fila['precio_propuesto'] = $fila['precio_cargado'] ?? ($totales['venta_total'] > 0 ? $totales['venta_total'] : null);
            if ($fila['precio_cargado'] === null) {
                $fila['avisos'][] = $fila['precio_propuesto'] !== null
                    ? 'Sin precio: se propone la suma de ventas de sus tarifas.'
                    : 'Sin precio ni tarifas: hay que escribir el precio.';
            }

            $calculo = $this->costoEstimado->calcular($tour);
            $fila['costo_propuesto'] = $calculo['total'];
            foreach ($calculo['items'] as $sugerido) {
                if ($sugerido['motivo'] !== null) {
                    $fila['avisos'][] = 'Ítem '.$sugerido['item_id'].': '.$sugerido['motivo'];
                }
            }

            if ($fila['igv_mezclado']) {
                $fila['avisos'][] = 'IGV mezclado: irá en una línea por tipo de IGV (N14).';
            }
            if ($fila['ajuste_redondeo'] !== null && $fila['ajuste_redondeo'] != 0.0) {
                $fila['avisos'][] = 'Usa ajuste de redondeo (se retira en la 1b).';
            }

            return $fila;
        })->values()->all();
    }
}
