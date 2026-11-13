<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\TemporadaOcurrencia;
use App\Services\HoraPeru;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// T-022 — "¿esta propuesta está lista para el cliente?". EXACTAMENTE 6 revisiones (ver el punto 8
// de docs/requerimientos/observaciones-pruebas-usuario.md); la regla de fondo es que la revisión
// acelera el envío y calla cuando todo está bien. No agregar otra: anotarla en la tarea.
//
//  1. sin servicios .................................. bloquea
//  2. servicio sin precio de venta (nulo o 0) ........ bloquea
//  3. venta bajo el costo, o markup bajo el mínimo ... aviso
//  4. tarifa con precio nuevo / que no vale al viaje . aviso (dos códigos)
//  5. pasaje aéreo con la tarifa vencida ............. aviso
//  6. propuesta enviada y vencida .................... aviso
//
// Cada entrada agrupa por código: una por tipo, con `cantidad` y a dónde navegar.
class RevisionAlternativaService
{
    public const BLOQUEA = 'bloquea';
    public const AVISO = 'aviso';

    // Mismo valor por defecto que ConfiguracionAgenciaController (la columna no admite vacío).
    private const MARKUP_MINIMO_POR_DEFECTO = 20.0;

    public function __construct(private readonly PriceEngineService $priceEngine)
    {
    }

    /**
     * @return list<array{codigo: string, nivel: string, mensaje: string, cantidad: int, destino: array{item_ids: list<int>, seccion: ?string}, detalle: list<array<string, mixed>>}>
     */
    public function revisar(Alternativa $alternativa): array
    {
        $alternativa->loadMissing('cotizacion');
        $fechaViaje = $alternativa->cotizacion?->fecha_viaje_desde
            ? Carbon::parse($alternativa->cotizacion->fecha_viaje_desde)->toDateString()
            : null;

        // Los "Ajuste — …" (T-072) son de la reserva: no son servicios de esta propuesta.
        $items = $alternativa->items()->with(['proveedorTarifa', 'cotizacionPasajeAereo'])->get()
            ->whereNull('ajuste_de_item_id')->values()
            ->each(fn (AlternativaItem $i) => $i->setRelation('alternativa', $alternativa));

        $revision = [];

        if ($items->isEmpty()) {
            $revision[] = $this->entrada('sin_servicios', self::BLOQUEA, 'La propuesta no tiene servicios.', [], 'lienzo');
        }

        $sinPrecio = $items->filter(fn (AlternativaItem $i) => $this->centavos($i->precio_convertido ?? $i->precio_venta_snapshot) <= 0);
        if ($sinPrecio->isNotEmpty()) {
            $revision[] = $this->entrada('precio_cero', self::BLOQUEA, $this->plural($sinPrecio->count(), 'servicio sin precio de venta', 'servicios sin precio de venta'), $sinPrecio->pluck('id')->all());
        }

        // Un servicio sin precio ya bloquea: no se repite como "venta bajo el costo".
        $this->markupBajo($items->diff($sinPrecio), $alternativa, $revision);
        $this->tarifas($items, $fechaViaje, $revision);

        if ($fechaViaje !== null) {
            $this->pasajesAereos($items, $alternativa, $revision);
        }

        $this->propuestaVencida($alternativa, $revision);

        return $revision;
    }

    // ¿Se puede enviar? null = sí. Si no, la respuesta 422 lista para devolver: la comparten
    // CotizacionEnvioController::enviar() y CotizacionActividadController::marcarEnviada()
    // (WhatsApp / otro medio) para que la regla sea una sola.
    public function bloqueoDeEnvio(Alternativa $alternativa): ?array
    {
        $revision = $this->revisar($alternativa);
        $bloqueos = array_filter($revision, fn (array $r) => $r['nivel'] === self::BLOQUEA);

        if ($bloqueos === []) {
            return null;
        }

        return [
            'code' => 422,
            'message' => 'No se puede enviar todavía: '.implode(' ', array_column($bloqueos, 'mensaje')),
            'revision' => $revision,
        ];
    }

    // ── 3. Venta bajo el costo / markup bajo el mínimo ───────────────

    private function markupBajo(Collection $items, Alternativa $alternativa, array &$revision): void
    {
        $minimo = (float) (ConfiguracionAgencia::first()?->margen_minimo_aceptable_pct ?? self::MARKUP_MINIMO_POR_DEFECTO);
        $afectados = [];
        $detalle = [];

        foreach ($items as $item) {
            $costo = $this->centavos($this->priceEngine->convertirMoneda(
                (float) $item->costo_snapshot, $item->moneda_costo, $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado,
            ) * ($item->modo_precio === 'tarifa_fija' ? $item->cantidad : 1));
            $venta = $this->centavos($item->total_convertido);

            if ($costo <= 0) {
                continue; // Sin costo no hay markup que evaluar.
            }

            $bajoCosto = $venta < $costo;
            // Markup = (venta − costo) ÷ costo, como utils/margenes.ts::markupPct (no el margen).
            $bajoMinimo = $minimo > 0 && ($venta - $costo) * 100 < $minimo * $costo;

            if ($bajoCosto || $bajoMinimo) {
                $afectados[] = $item->id;
                $detalle[] = ['item_id' => $item->id, 'markup_pct' => round(($venta - $costo) / $costo * 100, 2), 'bajo_costo' => $bajoCosto];
            }
        }

        if ($afectados === []) {
            return;
        }

        $mensaje = $this->plural(count($afectados), 'servicio', 'servicios')
            .($minimo > 0
                ? ' con la venta bajo el costo o con markup bajo el mínimo ('.$this->numero($minimo).' %)'
                : ' con la venta bajo el costo');
        $revision[] = $this->entrada('markup_bajo', self::AVISO, $mensaje, $afectados, null, $detalle);
    }

    // ── 4. Tarifa con precio nuevo / que no vale para el viaje ───────

    private function tarifas(Collection $items, ?string $fechaViaje, array &$revision): void
    {
        $conTarifa = $items->filter(fn (AlternativaItem $i) => $i->origen_tipo === AlternativaItem::ORIGEN_PROVEEDOR && $i->proveedorTarifa);
        $cubiertas = $fechaViaje !== null ? $this->temporadasQueCubren($conTarifa, $fechaViaje) : [];

        $conPrecioNuevo = [];
        $detallePrecioNuevo = [];
        $noVigentes = [];

        foreach ($conTarifa as $item) {
            $tarifa = $item->proveedorTarifa;
            $nueva = $tarifa->siguienteVersionVigente();

            if ($nueva) {
                $conPrecioNuevo[] = $item->id;
                $detallePrecioNuevo[] = [
                    'item_id' => $item->id,
                    'tarifa_nueva_id' => $nueva->id,
                    'precio_actual' => round($item->total_convertido, 2),
                    'precio_nuevo' => $this->priceEngine->precioDeItemConTarifa($item, $nueva, $tarifa)['total_convertido'],
                ];
                continue; // El aviso útil es el precio nuevo, no que la versión vieja ya cerró.
            }

            if ($fechaViaje !== null && $this->noVale($tarifa, $fechaViaje, $cubiertas)) {
                $noVigentes[] = $item->id;
            }
        }

        if ($conPrecioNuevo !== []) {
            $revision[] = $this->entrada('tarifa_precio_nuevo', self::AVISO, $this->plural(count($conPrecioNuevo), 'servicio con precio nuevo en su tarifa', 'servicios con precio nuevo en su tarifa'), $conPrecioNuevo, null, $detallePrecioNuevo);
        }
        if ($noVigentes !== []) {
            $revision[] = $this->entrada('tarifa_no_vigente', self::AVISO, $this->plural(count($noVigentes), 'servicio con tarifa que no vale para la fecha del viaje', 'servicios con tarifa que no vale para la fecha del viaje'), $noVigentes);
        }
    }

    private function noVale(ProveedorTarifa $tarifa,string $fechaViaje, array $temporadasCubiertas): bool
    {
        if (! $tarifa->activo) {
            return true;
        }
        if ($tarifa->vigente_hasta !== null && $tarifa->vigente_hasta->toDateString() < $fechaViaje) {
            return true;
        }

        return $tarifa->temporada_id !== null && ! in_array((int) $tarifa->temporada_id, $temporadasCubiertas, true);
    }

    // Ids de temporada (base central) con una ocurrencia que cubre la fecha. Una sola consulta.
    private function temporadasQueCubren(Collection $items, string $fecha): array
    {
        $ids = $items->map(fn (AlternativaItem $i) => $i->proveedorTarifa->temporada_id)->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return TemporadaOcurrencia::whereIn('temporada_id', $ids)
            ->where('fecha_desde', '<=', $fecha)
            ->where('fecha_hasta', '>=', $fecha)
            ->pluck('temporada_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ── 5. Pasaje aéreo con la tarifa vencida ────────────────────────

    private function pasajesAereos(Collection $items, Alternativa $alternativa, array &$revision): void
    {
        $ahora = Carbon::now();
        $vigenciaPropuesta = $this->vigenciaPropuesta($alternativa);
        $afectados = [];

        foreach ($items as $item) {
            $limite = $item->cotizacionPasajeAereo?->tarifa_valida_hasta;
            if ($limite && ($limite->lt($ahora) || ($vigenciaPropuesta && $limite->lt($vigenciaPropuesta)))) {
                $afectados[] = $item->id;
            }
        }

        if ($afectados !== []) {
            $revision[] = $this->entrada('pasaje_tarifa_vencida', self::AVISO, $this->plural(count($afectados), 'pasaje aéreo con la tarifa vencida o que vence antes que la propuesta', 'pasajes aéreos con la tarifa vencida o que vence antes que la propuesta'), $afectados, 'pasaje_aereo');
        }
    }

    // Hasta cuándo vale la propuesta: su vencimiento si ya lo tiene, o el que tendría si se envía ahora.
    private function vigenciaPropuesta(Alternativa $alternativa): ?Carbon
    {
        if ($alternativa->fecha_vencimiento) {
            return Carbon::instance($alternativa->fecha_vencimiento);
        }

        $dias = ConfiguracionAgencia::first()?->dias_vigencia_cotizacion;

        return $alternativa->estado === 'borrador' && $dias ? Carbon::now()->addDays((int) $dias) : null;
    }

    // ── 6. Propuesta enviada y vencida ───────────────────────────────

    private function propuestaVencida(Alternativa $alternativa, array &$revision): void
    {
        if ($alternativa->estado !== 'enviada' || ! $alternativa->fecha_vencimiento || ! $alternativa->fecha_vencimiento->lt(Carbon::now())) {
            return;
        }

        $dia = HoraPeru::deUtc($alternativa->fecha_vencimiento)->subSecond()->format('d/m/Y');
        $revision[] = $this->entrada('propuesta_vencida', self::AVISO, "La propuesta ya venció (el {$dia}): se reenviarían precios viejos.", [], 'datos_cotizacion');
    }

    // ── Utilidades ───────────────────────────────────────────────────

    private function entrada(string $codigo, string $nivel, string $mensaje, array $itemIds, ?string $seccion = null, array $detalle = []): array
    {
        return [
            'codigo' => $codigo,
            'nivel' => $nivel,
            'mensaje' => $mensaje,
            'cantidad' => $itemIds === [] ? 1 : count($itemIds),
            'destino' => ['item_ids' => array_values($itemIds), 'seccion' => $seccion],
            'detalle' => $detalle,
        ];
    }

    private function centavos(mixed $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    private function plural(int $n, string $singular, string $plural): string
    {
        return $n.' '.($n === 1 ? $singular : $plural);
    }

    private function numero(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
