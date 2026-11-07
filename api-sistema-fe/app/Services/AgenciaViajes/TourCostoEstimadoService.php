<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Services\HoraPeru;
use Illuminate\Support\Collection;

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
    // $dia: fecha de negocio (Y-m-d) en la que debe estar vigente; null = hoy.
    private function tarifaDeTemporada(ProveedorTarifa $referencia, int $temporadaId, ?string $dia = null): ?ProveedorTarifa
    {
        return $this->tarifasDeTemporada(collect([$referencia]), $temporadaId, $dia)->get($referencia->id);
    }

    /**
     * La misma regla de tarifaDeTemporada() para varias referencias con UNA
     * consulta (T-089: la salida no consulta una vez por servicio).
     *
     * @param  Collection<int, ProveedorTarifa>  $referencias
     * @return Collection<int, ProveedorTarifa> tarifa de la temporada, por id de la referencia
     */
    private function tarifasDeTemporada(Collection $referencias, int $temporadaId, ?string $dia = null): Collection
    {
        if ($referencias->isEmpty()) {
            return collect();
        }

        $candidatas = ProveedorTarifa::query()
            ->whereIn('proveedor_servicio_id', $referencias->pluck('proveedor_servicio_id')->unique()->values())
            ->whereNull('tipo_habitacion')
            ->where('temporada_id', $temporadaId)
            ->vigentes($dia)
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->get();

        return $referencias->mapWithKeys(fn (ProveedorTarifa $ref) => [
            $ref->id => $candidatas->first(fn (ProveedorTarifa $t) => $t->proveedor_servicio_id === $ref->proveedor_servicio_id
                && $t->modalidad === $ref->modalidad),
        ])->filter();
    }

    /**
     * Ganancia ESTIMADA de una salida (T-089, P8 a): lo cobrado a sus reservas
     * − costos fijos (tarifas privadas × vehículos, guía × días) − costos por
     * persona × pasajeros que ocupan asiento. Con tarifas, no con facturas: la
     * real llega con Compras. Todo en la moneda del tour; sin IGV (P4b).
     * Suma en centavos enteros: no hay otro redondeo que el de pasar a soles.
     *
     * null si la salida no tiene tour de origen (no hay Incluye que costear).
     *
     * @return ?array{moneda: string, cobrado: float, costo_fijo: float, costo_variable: float, costo_por_persona: float, ganancia: float, markup_pct: ?float, margen_pct: ?float, pasajeros: int, vehiculos: int, completo: bool, avisos: array<int, string>}
     */
    public function estimarSalida(SalidaOperativa $salida): ?array
    {
        $salida->loadMissing([
            'tourOrigen',
            'vehiculos',
            'reservaItems.reserva.pasajeros',
            'reservaItems.alternativaItem.alternativa',
        ]);
        $tour = $salida->tourOrigen;
        if (! $tour) {
            return null;
        }

        $moneda = $tour->moneda ?? 'PEN';
        $avisos = [];

        // ── Lo cobrado y los pasajeros (mismo criterio que el tablero) ──
        $operacion = app(SalidaOperacionService::class);
        $reservas = $operacion->reservasActivas($salida);
        $idsActivas = $reservas->pluck('id');
        $pasajeros = $operacion->ocupan($reservas->flatMap(fn ($r) => $r->pasajeros));

        $cobradoCts = 0;
        $reservasOtraMoneda = []; // moneda => [reserva_id => true]
        foreach ($salida->reservaItems->filter(fn ($i) => $idsActivas->contains($i->reserva_id)) as $item) {
            $alt = $item->alternativaItem;
            $monedaItem = $alt?->alternativa?->moneda_cotizacion ?? 'PEN';
            if ($monedaItem !== $moneda) {
                $reservasOtraMoneda[$monedaItem][$item->reserva_id] = true;

                continue;
            }
            $cobradoCts += $this->centavos($alt?->total_convertido ?? 0);
        }
        foreach ($reservasOtraMoneda as $otra => $ids) {
            $n = count($ids);
            $avisos[] = $n === 1
                ? "1 reserva en {$otra} no está sumada."
                : "{$n} reservas en {$otra} no están sumadas.";
        }

        // ── Costos del Incluye ──
        $incluye = $tour->items()
            ->whereNull('paquete_plantilla_hijo_id')
            ->with(['proveedorTarifa', 'guiaTarifa', 'destinoServicio.servicio'])
            ->orderBy('orden')
            ->get();
        $dias = max(1, (int) ($tour->paqueteItinerario()->max('dia_relativo') ?? 1));
        $vehiculos = max(1, $salida->vehiculos->count());
        $dia = $salida->fecha->toDateString();

        // Tarifa de la temporada de la fecha de la salida (si el catálogo la tiene).
        $temporadaId = app(TemporadaResolverService::class)->resolver($dia)?->temporada_id;
        $deTemporada = $temporadaId !== null
            ? $this->tarifasDeTemporada(
                $incluye->pluck('proveedorTarifa')->filter()->unique('id')->values(),
                $temporadaId,
                $dia
            )
            : collect();

        $fijoCts = 0;
        $variableCts = 0; // por persona
        $sinCosto = [];

        foreach ($incluye as $item) {
            $nombre = $item->guia_tarifa_id ? 'Guía' : ($item->destinoServicio?->servicio?->nombre ?? 'Servicio');
            $tarifa = $item->proveedor_tarifa_id
                ? ($deTemporada->get($item->proveedor_tarifa_id) ?? $item->proveedorTarifa)
                : null;

            if ($item->guia_tarifa_id && $item->guiaTarifa && $item->guiaTarifa->moneda === $moneda) {
                $fijoCts += $this->centavos($item->guiaTarifa->costo_diario) * $dias;
            } elseif ($tarifa && $tarifa->tipo_habitacion === null && $tarifa->moneda === $moneda) {
                $costo = $this->centavos($tarifa->precio_costo);
                if ($tarifa->modalidad === 'compartido') {
                    $variableCts += $costo;
                } else {
                    $fijoCts += $costo * $vehiculos;
                }
            } elseif ($item->costo_estimado_persona !== null) {
                // Sin tarifa (o una que no se puede usar: hotel, otra moneda): el costo escrito a mano.
                $variableCts += $this->centavos($item->costo_estimado_persona);
            } else {
                $sinCosto[] = $nombre;
            }
        }

        foreach ($sinCosto as $nombre) {
            $avisos[] = "«{$nombre}» no tiene costo: no está sumado.";
        }
        // Sin servicios en el Incluye no hay nada que costear: no es "completo"
        // (misma regla que calcular() y costoDelTour()), si no la ganancia
        // saldría igual a lo cobrado y en verde.
        if ($incluye->isEmpty()) {
            $avisos[] = 'El tour no tiene servicios en el Incluye: el costo no está sumado.';
        }

        $variableTotalCts = $variableCts * $pasajeros;
        $costoCts = $fijoCts + $variableTotalCts;
        $gananciaCts = $cobradoCts - $costoCts;

        return [
            'moneda' => $moneda,
            'cobrado' => $cobradoCts / 100,
            'costo_fijo' => $fijoCts / 100,
            'costo_variable' => $variableTotalCts / 100,
            'costo_por_persona' => $variableCts / 100,
            'ganancia' => $gananciaCts / 100,
            // Misma fórmula que admin-start-kit/src/utils/margenes.ts (T-087).
            'markup_pct' => $costoCts > 0 ? round($gananciaCts / $costoCts * 100, 2) : null,
            'margen_pct' => $cobradoCts > 0 ? round($gananciaCts / $cobradoCts * 100, 2) : null,
            'pasajeros' => $pasajeros,
            'vehiculos' => $vehiculos,
            'completo' => $sinCosto === [] && $incluye->isNotEmpty(),
            'avisos' => $avisos,
        ];
    }

    // Monto con 2 decimales → centavos enteros (los montos de la base ya tienen 2).
    private function centavos(mixed $monto): int
    {
        return (int) round(((float) $monto) * 100);
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
