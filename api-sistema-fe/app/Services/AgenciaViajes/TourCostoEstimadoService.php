<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Services\HoraPeru;

// Costo estimado del tour y calculadora desde tarifas — fila 13, fase 1a
// (plan-tours-venta-simple-costo-real.md §3.1, §3.2, §3.9b, N4).
//
// Calculadora: SUGIERE el costo por persona de cada servicio del Incluye a
// partir de su tarifa de referencia; no guarda nada (la pantalla aplica y
// guarda lo que el usuario acepte). Reglas:
//   - tarifa 'compartido' (por persona): el costo tal cual;
//   - tarifa 'privado' (por vehículo/grupo): costo ÷ pax_minimo_salida;
//   - guía: costo_diario × días del tour ÷ pax_minimo_salida. Días = máximo
//     dia_relativo del itinerario ?? 1 (resolverCostoVentaItem() del
//     cotizador lo sigue contando como 1 día: error E5, no se toca acá).
//   - con temporada: si el mismo servicio del proveedor tiene una tarifa de
//     esa temporada (misma modalidad, activa y vigente), se usa esa; si no,
//     la de referencia.
// Nunca inventa un número: sin tarifa, tarifa de hotel (el hotel no va dentro
// del precio del tour, §3.4), moneda distinta a la del tour, o un costo por
// grupo sin mínimo para salir → costo null con el motivo.
//
// Costo del tour: la suma de los costos del Incluye cuando TODOS sus
// servicios lo tienen; si falta alguno, el escrito a mano en el tour.
class TourCostoEstimadoService
{
    /**
     * @return array{costo: ?float, costo_ext: ?float, origen: ?string, items_sin_costo: int}
     */
    public function costoDelTour(PaquetePlantilla $tour): array
    {
        $items = $tour->relationLoaded('items') ? $tour->items : $tour->items()->get();
        $items = $items->whereNull('paquete_plantilla_hijo_id');
        $sinCosto = $items->filter(fn (PaquetePlantillaItem $i) => $i->costo_estimado_persona === null)->count();

        if ($items->isNotEmpty() && $sinCosto === 0) {
            $costo = $items->sum(fn ($i) => (float) $i->costo_estimado_persona);
            $costoExt = $items->sum(fn ($i) => (float) ($i->costo_estimado_persona_ext ?? $i->costo_estimado_persona));

            return [
                'costo' => round($costo, 2),
                'costo_ext' => round($costoExt, 2),
                'origen' => 'incluye',
                'items_sin_costo' => 0,
            ];
        }

        if ($tour->costo_estimado_persona !== null) {
            return [
                'costo' => (float) $tour->costo_estimado_persona,
                'costo_ext' => (float) ($tour->costo_estimado_persona_ext ?? $tour->costo_estimado_persona),
                'origen' => 'manual',
                'items_sin_costo' => $sinCosto,
            ];
        }

        return ['costo' => null, 'costo_ext' => null, 'origen' => null, 'items_sin_costo' => $sinCosto];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: ?float, dias: int, temporada_id: ?int}
     */
    public function calcular(PaquetePlantilla $tour, ?int $temporadaId = null): array
    {
        $dias = max(1, (int) ($tour->paqueteItinerario()->max('dia_relativo') ?? 1));
        $items = $tour->items()
            ->whereNull('paquete_plantilla_hijo_id')
            ->with(['proveedorTarifa', 'guiaTarifa', 'destinoServicio.servicio'])
            ->orderBy('orden')
            ->get();

        $resultado = $items->map(fn (PaquetePlantillaItem $item) => ['item_id' => $item->id]
            + $this->sugerirItem($tour, $item, $dias, $temporadaId))->values()->all();

        $costos = array_column($resultado, 'costo_sugerido');
        $completo = $resultado !== [] && ! in_array(null, $costos, true);

        return [
            'items' => $resultado,
            'total' => $completo ? round(array_sum($costos), 2) : null,
            'dias' => $dias,
            'temporada_id' => $temporadaId,
        ];
    }

    /**
     * @return array{costo_sugerido: ?float, detalle: ?string, motivo: ?string, tarifa_usada_id: ?int, de_temporada: bool}
     */
    private function sugerirItem(PaquetePlantilla $tour, PaquetePlantillaItem $item, int $dias, ?int $temporadaId): array
    {
        $minimo = $tour->pax_minimo_salida ?: null;
        $moneda = $tour->moneda ?? 'PEN';

        if ($item->proveedor_tarifa_id) {
            $tarifa = $item->proveedorTarifa;
            $deTemporada = false;

            if ($temporadaId !== null) {
                $deLaTemporada = $this->tarifaDeTemporada($tarifa, $temporadaId);
                if ($deLaTemporada) {
                    $tarifa = $deLaTemporada;
                    $deTemporada = true;
                }
            }

            if ($tarifa->tipo_habitacion !== null) {
                return $this->sinSugerencia('Es una tarifa de hotel: el hotel no va dentro del precio del tour.', $tarifa->id);
            }

            if ($tarifa->moneda !== $moneda) {
                return $this->sinSugerencia("La tarifa está en {$tarifa->moneda} y el tour en {$moneda}.", $tarifa->id);
            }

            $costo = (float) $tarifa->precio_costo;

            if ($tarifa->modalidad === 'compartido') {
                return $this->sugerencia($costo, 'Por persona: '.number_format($costo, 2), $tarifa->id, $deTemporada);
            }

            if ($minimo === null) {
                return $this->sinSugerencia('Se cobra por vehículo o grupo: falta el mínimo para salir del tour.', $tarifa->id);
            }

            return $this->sugerencia(
                $costo / $minimo,
                'Por grupo: '.number_format($costo, 2)." ÷ {$minimo} pax",
                $tarifa->id,
                $deTemporada
            );
        }

        if ($item->guia_tarifa_id) {
            $guia = $item->guiaTarifa;

            if ($guia->moneda !== $moneda) {
                return $this->sinSugerencia("La tarifa del guía está en {$guia->moneda} y el tour en {$moneda}.", null);
            }

            if ($minimo === null) {
                return $this->sinSugerencia('El guía se cobra por día: falta el mínimo para salir del tour.', null);
            }

            $total = (float) $guia->costo_diario * $dias;

            return $this->sugerencia(
                $total / $minimo,
                'Guía: '.number_format((float) $guia->costo_diario, 2)." × {$dias} día(s) ÷ {$minimo} pax",
                null,
                false
            );
        }

        return $this->sinSugerencia('Sin tarifa de referencia: escribe el costo a mano.', null);
    }

    // Tarifa del mismo servicio del proveedor para la temporada pedida.
    private function tarifaDeTemporada(ProveedorTarifa $referencia, int $temporadaId): ?ProveedorTarifa
    {
        $hoy = HoraPeru::hoyTexto();

        return ProveedorTarifa::query()
            ->where('proveedor_servicio_id', $referencia->proveedor_servicio_id)
            ->where('modalidad', $referencia->modalidad)
            ->whereNull('tipo_habitacion')
            ->where('temporada_id', $temporadaId)
            ->where('activo', true)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $hoy))
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->first();
    }

    private function sugerencia(float $costo, string $detalle, ?int $tarifaId, bool $deTemporada): array
    {
        return [
            'costo_sugerido' => round($costo, 2),
            'detalle' => $detalle,
            'motivo' => null,
            'tarifa_usada_id' => $tarifaId,
            'de_temporada' => $deTemporada,
        ];
    }

    private function sinSugerencia(string $motivo, ?int $tarifaId): array
    {
        return [
            'costo_sugerido' => null,
            'detalle' => null,
            'motivo' => $motivo,
            'tarifa_usada_id' => $tarifaId,
            'de_temporada' => false,
        ];
    }
}
