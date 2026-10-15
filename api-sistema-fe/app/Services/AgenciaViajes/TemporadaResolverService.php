<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaPrecio;
use App\Models\AgenciaViajes\TemporadaOcurrencia;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Resolución de temporada por fecha — fila 13, fase 1a
// (plan-tours-venta-simple-costo-real.md §3.12, N16). Dada una fecha
// (Y-m-d, fecha de negocio: el día del tour), qué ocurrencia de
// `temporadas`/`temporada_ocurrencias` la cubre. Bordes inclusivos.
// Superpuestas (Fiestas Patrias dentro de Temporada Alta): gana la de rango
// MÁS CORTO (la más específica); empate → la de id menor, para que el
// resultado sea siempre el mismo.
//
// La usa la sesión 1b al cargar el tour en la cotización; acá queda hecha,
// probada y expuesta solo como vista previa en la ficha del tour.
// `elegir()` es puro (sin BD) para poder probar las reglas sin depender del
// catálogo central.
class TemporadaResolverService
{
    /**
     * @param  iterable<TemporadaOcurrencia>  $ocurrencias
     */
    public function elegir(iterable $ocurrencias, string $fecha): ?TemporadaOcurrencia
    {
        $dia = Carbon::parse($fecha)->startOfDay();

        return collect($ocurrencias)
            ->filter(fn (TemporadaOcurrencia $o) => $o->fecha_desde->copy()->startOfDay()->lte($dia)
                && $o->fecha_hasta->copy()->startOfDay()->gte($dia))
            ->sortBy([
                fn ($a, $b) => $this->duracionDias($a) <=> $this->duracionDias($b),
                fn ($a, $b) => $a->id <=> $b->id,
            ])
            ->first();
    }

    // Ocurrencia que cubre la fecha, opcionalmente solo entre ciertas
    // temporadas (las que tienen precio cargado en el tour: si la más
    // específica no tiene precio, se cae a la siguiente que sí lo tenga, no
    // directo al precio base).
    public function resolver(string $fecha, ?array $temporadaIds = null): ?TemporadaOcurrencia
    {
        if ($temporadaIds !== null && $temporadaIds === []) {
            return null;
        }

        $query = TemporadaOcurrencia::query()
            ->with('temporada')
            ->where('fecha_desde', '<=', $fecha)
            ->where('fecha_hasta', '>=', $fecha)
            ->whereHas('temporada', fn ($q) => $q->where('giro', 'agencia_viajes'));

        if ($temporadaIds !== null) {
            $query->whereIn('temporada_id', $temporadaIds);
        }

        return $this->elegir($query->get(), $fecha);
    }

    // Precio del tour para una fecha: la fila de paquete_plantilla_precios de
    // la temporada que la cubre, o null = precio base (columnas del tour).
    // Sin fecha → null (la 1b avisa "sin fecha: se usó el precio base").
    /**
     * @return array{precio: ?PaquetePlantillaPrecio, ocurrencia: ?TemporadaOcurrencia}
     */
    public function precioParaFecha(PaquetePlantilla $tour, ?string $fecha): array
    {
        if ($fecha === null) {
            return ['precio' => null, 'ocurrencia' => null];
        }

        $precios = $tour->precios()->get()->keyBy('temporada_id');
        $ocurrencia = $this->resolver($fecha, $precios->keys()->all());

        return [
            'precio' => $ocurrencia ? $precios->get($ocurrencia->temporada_id) : null,
            'ocurrencia' => $ocurrencia,
        ];
    }

    private function duracionDias(TemporadaOcurrencia $o): int
    {
        return (int) $o->fecha_desde->copy()->startOfDay()->diffInDays($o->fecha_hasta->copy()->startOfDay(), absolute: true);
    }
}
