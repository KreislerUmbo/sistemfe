<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

// El tour en la cotización como UNA línea — fila 13, fase 1b
// (plan-tours-venta-simple-costo-real.md N6, N7, N14, §3.3, §3.9b, §3.12).
// Es el camino modo_tours='nuevo'; el 'clasico' sigue siendo
// AlternativaItemController::desdePlantilla(), sin cambios.
//
// Reglas:
// - Precio = Σ por pasajero del precio que le toca (adulto/niño × nacional/
//   extranjero, con los vacíos de la 1a: niño → adulto, extranjero →
//   nacional). El infante paga 0 y no suma costo.
// - Temporada: la del DÍA del tour (fecha_viaje_desde + dia − 1), vía
//   TemporadaResolverService::precioParaFecha(). Sin fecha: precio base.
// - IGV (N14): una línea por grupo de IGV del Incluye; el precio se reparte en
//   proporción al costo estimado de cada grupo y los centavos sobrantes van al
//   grupo de mayor costo. Todas las líneas de una carga comparten carga_tour.
// - Combo (N7, E3): una carga por tour, cada una en su día y con su temporada;
//   el precio del combo (suma − descuento) se reparte entre sus tours en
//   proporción al precio de lista. Descuento "S/ fijo" = por persona que paga
//   (decisión del usuario, 14-oct-2026).
//
// Cada línea guarda en tour_linea los datos con los que se calculó (precios y
// costos unitarios, grupos de IGV, descuento del combo), así que recalcular al
// cambiar pasajeros NO vuelve a leer el catálogo: la temporada y los precios
// cotizados se mantienen. Solo "Recalcular precios por temporada" relee el
// catálogo, y solo cuando el vendedor lo pide (§3.12).
class TourLineaService
{
    public function __construct(
        private PriceEngineService $priceEngine,
        private ComboExplosionService $comboExplosion,
        private TemporadaResolverService $temporadaResolver,
        private TourCostoEstimadoService $costoEstimado,
    ) {
    }

    // ═══════════════════════════════════════════════════════════════
    // Cargar un tour o un combo
    // ═══════════════════════════════════════════════════════════════

    /**
     * Crea las líneas de tour (sin transacción propia: la abre el caller).
     * Los ítems sueltos de un combo NO los crea: siguen el camino clásico en
     * el controller, que los etiqueta con la carga_combo devuelta.
     *
     * @return array{items: array<int, AlternativaItem>, avisos: array<int, string>, dia_final_combo: int, carga_combo: ?string}
     */
    public function cargar(Alternativa $alternativa, PaquetePlantilla $paquete, int $dia, ?int $alternativaDestinoId): array
    {
        $fechaDesde = $alternativa->cotizacion?->fecha_viaje_desde;
        $avisos = [];
        $tours = []; // [tour, dia]
        $combo = null;
        $diaFinal = $dia;

        if ($paquete->esPaqueteCombo()) {
            $offset = 0;
            foreach ($this->comboExplosion->toursDelCombo($paquete) as $tour) {
                $diasTour = (int) ($tour->paqueteItinerario()->max('dia_relativo') ?? 1);

                if (! $tour->activo) {
                    $avisos[] = "«{$tour->nombre}» está desactivado: no se cargó.";
                } else {
                    $tours[] = [$tour, $dia + $offset];
                    $diaFinal = max($diaFinal, $dia + $offset + $diasTour - 1);
                }

                $offset += $diasTour;
            }

            if ($tours === []) {
                throw new TourLineaException("El combo «{$paquete->nombre}» no tiene tours activos para cargar.");
            }

            $combo = [
                'id' => $paquete->id,
                'nombre' => $paquete->nombre,
                'descuento_tipo' => $paquete->descuento_tipo,
                'descuento_valor' => $paquete->descuento_valor !== null ? (float) $paquete->descuento_valor : null,
            ];
        } else {
            $tours[] = [$paquete, $dia];
            $diaFinal = $dia + (int) ($paquete->paqueteItinerario()->max('dia_relativo') ?? 1) - 1;
        }

        $specs = [];
        foreach ($tours as [$tour, $diaTour]) {
            $spec = $this->specDelTour($tour, $this->fechaDelDia($fechaDesde, $diaTour));
            $spec['dia'] = $diaTour;
            $specs[] = $spec;
        }

        if (count(array_unique(array_column($specs, 'moneda'))) > 1) {
            throw new TourLineaException("Los tours del combo «{$paquete->nombre}» tienen monedas distintas: no se puede repartir su descuento.");
        }

        if ($fechaDesde === null) {
            $avisos[] = 'La cotización no tiene fecha de viaje: se usó el precio base del tour.';
        }
        foreach ($specs as $spec) {
            if ($spec['sin_costo']) {
                $avisos[] = "«{$spec['tour_nombre']}» no tiene costo estimado: la línea queda con costo 0.";
            }
        }

        $calculo = $this->calcular($specs, $this->conteo($alternativa->cotizacion_id), $combo);
        $cargaCombo = $combo ? (string) Str::uuid() : null;
        $items = [];

        foreach ($specs as $t => $spec) {
            $cargaTour = (string) Str::uuid();

            foreach ($calculo[$t]['lineas'] as $g => $linea) {
                $grupo = $spec['grupos'][$g];

                $items[] = AlternativaItem::create([
                    'alternativa_id' => $alternativa->id,
                    'alternativa_destino_id' => $alternativaDestinoId,
                    'origen_tipo' => AlternativaItem::ORIGEN_TOUR,
                    'tour_origen_id' => $spec['tour_id'],
                    'combo_origen_id' => $combo['id'] ?? null,
                    'carga_tour' => $cargaTour,
                    'carga_combo' => $cargaCombo,
                    'dia_referencial' => $spec['dia'],
                    'modo_precio' => 'por_persona',
                    'cantidad' => 1,
                    'moneda_costo' => $spec['moneda'],
                    'costo_snapshot' => $linea['costo'],
                    'precio_venta_snapshot' => $linea['precio'],
                    'precio_convertido' => $this->convertir($linea['precio'], $spec['moneda'], $alternativa),
                    'tip_afe_igv' => $grupo['tip_afe_igv'],
                    'destino_tributario' => $grupo['destino_tributario'],
                    'temporada_id' => $spec['temporada_id'],
                    'tour_linea' => $this->datosLinea($spec, $g, $combo),
                    'desglose_costo_snapshot' => $linea['desglose'],
                ]);
            }
        }

        return ['items' => $items, 'avisos' => $avisos, 'dia_final_combo' => $diaFinal, 'carga_combo' => $cargaCombo];
    }

    // ═══════════════════════════════════════════════════════════════
    // Datos del tour para una fecha (catálogo → spec)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Precios, costos y grupos de IGV del tour para el día del tour. Valida
     * que se pueda cargar (venta, con precio, IGV mezclado con costos).
     */
    public function specDelTour(PaquetePlantilla $tour, ?string $fechaTour): array
    {
        if (! $tour->esTourSimple()) {
            throw new TourLineaException("«{$tour->nombre}» no es un tour simple.");
        }
        if ($tour->esSoloItinerario()) {
            throw new TourLineaException("«{$tour->nombre}» es un tour solo de itinerario (de mayorista): no se puede cargar en la cotización.");
        }
        if ($tour->precio_venta_final === null) {
            throw new TourLineaException("«{$tour->nombre}» no tiene precio de venta: complétalo en el catálogo antes de cargarlo.");
        }

        ['precio' => $fila, 'ocurrencia' => $ocurrencia] = $this->temporadaResolver->precioParaFecha($tour, $fechaTour);

        $base = $fila
            ? [$fila->precio_venta_adulto, $fila->precio_venta_nino, $fila->precio_venta_adulto_ext, $fila->precio_venta_nino_ext]
            : [$tour->precio_venta_final, $tour->precio_venta_nino, $tour->precio_venta_adulto_ext, $tour->precio_venta_nino_ext];
        $adulto = (float) $base[0];
        $nino = $base[1] !== null ? (float) $base[1] : $adulto;
        $precios = [
            'adulto' => $adulto,
            'nino' => $nino,
            'adulto_ext' => $base[2] !== null ? (float) $base[2] : $adulto,
            'nino_ext' => $base[3] !== null ? (float) $base[3] : $nino,
        ];

        // Costo del tour por persona: el de la temporada si lo tiene; si no,
        // el del catálogo (suma del Incluye o escrito a mano).
        if ($fila && $fila->costo_estimado_persona !== null) {
            $costoNac = (float) $fila->costo_estimado_persona;
            $costoExt = (float) ($fila->costo_estimado_persona_ext ?? $fila->costo_estimado_persona);
        } else {
            $costoTour = $this->costoEstimado->costoDelTour($tour);
            $costoNac = $costoTour['costo'];
            $costoExt = $costoTour['costo_ext'];
        }
        $sinCosto = $costoNac === null;
        $costoNac = (float) ($costoNac ?? 0);
        $costoExt = (float) ($costoExt ?? $costoNac);

        return [
            'tour_id' => $tour->id,
            'tour_nombre' => $tour->nombre,
            'moneda' => $tour->moneda ?? 'PEN',
            'fecha_tour' => $fechaTour,
            'temporada_id' => $ocurrencia?->temporada_id,
            'temporada_nombre' => $ocurrencia?->temporada?->nombre,
            'precios' => $precios,
            'sin_costo' => $sinCosto,
            'grupos' => $this->gruposDeIgv($tour, $costoNac, $costoExt),
        ];
    }

    // Servicios del Incluye agrupados por IGV (vacío = el del tour; el del
    // tour vacío = el default de la agencia). Cada grupo con su costo por
    // persona; la suma de los grupos es el costo del tour.
    private function gruposDeIgv(PaquetePlantilla $tour, float $costoNac, float $costoExt): array
    {
        $default = ConfiguracionAgencia::tratamientoTributarioDefault();
        $igvTour = [
            'tip_afe_igv' => $tour->tip_afe_igv ?? $default['tip_afe_igv'],
            'destino_tributario' => $tour->destino_tributario ?? $default['destino_tributario'],
        ];

        $items = $tour->items()
            ->whereNull('paquete_plantilla_hijo_id')
            ->with(['destinoServicio.servicio', 'proveedorTarifa.proveedorServicio.destinoServicio.servicio', 'guiaTarifa.guia'])
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        $grupos = [];
        foreach ($items as $item) {
            $igv = [
                'tip_afe_igv' => $item->tip_afe_igv ?? $igvTour['tip_afe_igv'],
                'destino_tributario' => $item->destino_tributario ?? $igvTour['destino_tributario'],
            ];
            // Fila 13 (22-oct-2026): una línea por CÓDIGO SUNAT, no por código +
            // destino: para SUNAT, dos servicios exonerados son una sola línea.
            // Si alguno se presta en la Amazonía, la línea queda como Amazonía
            // (leyenda de la Ley 27037 y confirmación antes de facturar).
            $clave = $igv['tip_afe_igv'];
            $grupos[$clave] ??= $igv + ['items' => []];
            if ($igv['destino_tributario'] === 'amazonia') {
                $grupos[$clave]['destino_tributario'] = 'amazonia';
            }
            $grupos[$clave]['items'][] = [
                'paquete_plantilla_item_id' => $item->id,
                'servicio' => $this->nombreServicio($item),
                'proveedor_id' => $item->proveedor_id,
                'costo_persona' => $item->costo_estimado_persona !== null ? (float) $item->costo_estimado_persona : null,
                'costo_persona_ext' => $item->costo_estimado_persona_ext !== null
                    ? (float) $item->costo_estimado_persona_ext
                    : ($item->costo_estimado_persona !== null ? (float) $item->costo_estimado_persona : null),
            ];
        }

        $grupos = array_values($grupos);

        if (count($grupos) <= 1) {
            return [($grupos[0] ?? $igvTour + ['items' => []]) + ['costo_persona' => $costoNac, 'costo_persona_ext' => $costoExt]];
        }

        foreach ($grupos as $grupo) {
            foreach ($grupo['items'] as $item) {
                if ($item['costo_persona'] === null) {
                    throw new TourLineaException("«{$tour->nombre}» mezcla tipos de IGV y el servicio «{$item['servicio']}» no tiene costo estimado: complétalo en el catálogo (pestaña Incluye y costos) para poder repartir el precio.");
                }
            }
        }

        // Con IGV mezclado todos los servicios tienen costo, así que la suma
        // del Incluye es el costo del tour; con una temporada que trae su
        // propio costo, se escala cada grupo a ese total.
        $sumaNac = array_sum(array_map(fn ($g) => array_sum(array_column($g['items'], 'costo_persona')), $grupos));
        $sumaExt = array_sum(array_map(fn ($g) => array_sum(array_column($g['items'], 'costo_persona_ext')), $grupos));
        $factorNac = $sumaNac > 0 ? $costoNac / $sumaNac : 0;
        $factorExt = $sumaExt > 0 ? $costoExt / $sumaExt : 0;

        return array_map(fn (array $g) => $g + [
            'costo_persona' => round(array_sum(array_column($g['items'], 'costo_persona')) * $factorNac, 2),
            'costo_persona_ext' => round(array_sum(array_column($g['items'], 'costo_persona_ext')) * $factorExt, 2),
        ], $grupos);
    }

    public static function nombreServicio(PaquetePlantillaItem $item): string
    {
        if ($item->guia_tarifa_id) {
            return 'Guía'.($item->guiaTarifa?->guia?->nombre ? ' — '.$item->guiaTarifa->guia->nombre : '');
        }

        return $item->destinoServicio?->servicio?->nombre
            ?? $item->proveedorTarifa?->proveedorServicio?->destinoServicio?->servicio?->nombre
            ?? 'Servicio';
    }

    // ═══════════════════════════════════════════════════════════════
    // Cálculo puro (sin BD)
    // ═══════════════════════════════════════════════════════════════

    /**
     * @param  array<int, array>  $specs  uno por tour (un tour suelto = uno)
     * @param  array{adulto_nac: int, nino_nac: int, adulto_ext: int, nino_ext: int, infante: int}  $conteo
     * @param  array{descuento_tipo: ?string, descuento_valor: ?float}|null  $combo
     * @return array<int, array{lista: float, precio: float, lineas: array<int, array{precio: float, costo: float, desglose: array}>}>
     */
    public function calcular(array $specs, array $conteo, ?array $combo): array
    {
        $paxNac = $conteo['adulto_nac'] + $conteo['nino_nac'];
        $paxExt = $conteo['adulto_ext'] + $conteo['nino_ext'];

        $listas = array_map(fn (array $s) => round(
            $conteo['adulto_nac'] * $s['precios']['adulto']
                + $conteo['nino_nac'] * $s['precios']['nino']
                + $conteo['adulto_ext'] * $s['precios']['adulto_ext']
                + $conteo['nino_ext'] * $s['precios']['nino_ext'],
            2
        ), $specs);

        $precios = $listas;
        if ($combo && $combo['descuento_tipo'] !== null && $combo['descuento_valor'] !== null) {
            $valor = $combo['descuento_tipo'] === 'monto'
                ? (float) $combo['descuento_valor'] * ($paxNac + $paxExt)
                : (float) $combo['descuento_valor'];
            $neto = max(0.0, $this->priceEngine->aplicarDescuento(array_sum($listas), $combo['descuento_tipo'], $valor));
            $precios = self::repartir($neto, $listas);
        }

        $resultado = [];
        foreach ($specs as $t => $spec) {
            $costos = array_map(fn (array $g) => round($g['costo_persona'] * $paxNac + $g['costo_persona_ext'] * $paxExt, 2), $spec['grupos']);
            $pesos = array_sum($costos) > 0 ? $costos : array_column($spec['grupos'], 'costo_persona');
            $preciosGrupo = count($spec['grupos']) === 1 ? [$precios[$t]] : self::repartir($precios[$t], $pesos);

            $lineas = [];
            foreach ($spec['grupos'] as $g => $grupo) {
                $lineas[] = [
                    'precio' => $preciosGrupo[$g],
                    'costo' => $costos[$g],
                    'desglose' => array_map(fn (array $i) => $i + ['pax_nacional' => $paxNac, 'pax_extranjero' => $paxExt], $grupo['items']),
                ];
            }

            $resultado[$t] = ['lista' => $listas[$t], 'precio' => $precios[$t], 'lineas' => $lineas];
        }

        return $resultado;
    }

    /**
     * Reparte un monto en proporción a los pesos, en centavos exactos: los
     * centavos sobrantes van a la parte de mayor peso (N14). Pesos todos en 0
     * = partes iguales.
     *
     * @param  array<int, float>  $pesos
     * @return array<int, float>
     */
    public static function repartir(float $total, array $pesos): array
    {
        $pesos = array_values(array_map('floatval', $pesos));
        $centavos = (int) round($total * 100);
        $suma = array_sum($pesos);

        if ($suma <= 0) {
            $pesos = array_fill(0, count($pesos), 1.0);
            $suma = (float) count($pesos);
        }

        $partes = array_map(fn (float $p) => (int) floor($centavos * $p / $suma + 1e-9), $pesos);
        $mayor = array_search(max($pesos), $pesos, true);
        $partes[$mayor] += $centavos - array_sum($partes);

        return array_map(fn (int $c) => round($c / 100, 2), $partes);
    }

    /**
     * Fila 13, 1c (C1): precio de lista de un grupo de pasajeros, con los
     * precios unitarios de tour_linea. El infante paga 0.
     *
     * @param  array{adulto: float, nino: float, adulto_ext: float, nino_ext: float}  $precios
     * @param  array<int, array{tipo_pax: string, residencia: ?string}>  $pasajeros
     */
    public static function precioDeLista(array $precios, array $pasajeros): float
    {
        $total = 0.0;

        foreach ($pasajeros as $pasajero) {
            if ($pasajero['tipo_pax'] === 'infante') {
                continue;
            }

            $clave = $pasajero['tipo_pax'] === 'nino' ? 'nino' : 'adulto';
            if ($pasajero['residencia'] === CotizacionPasajero::RESIDENCIA_EXTRANJERO) {
                $clave .= '_ext';
            }

            $total += $precios[$clave];
        }

        return round($total, 2);
    }

    /**
     * Fila 13, 1c (C1): lo que le toca a un grupo del precio vendido del tour.
     * El precio vendido puede diferir del de lista (descuento del combo, de la
     * alternativa o precio a mano): el grupo paga su lista en la misma
     * proporción. Los centavos de redondeo los ajusta quien factura el último
     * grupo.
     */
    public static function parteDelGrupo(float $listaGrupo, float $listaTotal, float $vendidoTotal): float
    {
        return round(self::parteExacta($listaGrupo, $listaTotal, $vendidoTotal), 2);
    }

    // Lo mismo sin redondear: lo usa repartirCentavos() cuando un tour tiene
    // varias líneas (una por código de IGV).
    public static function parteExacta(float $listaGrupo, float $listaTotal, float $vendidoTotal): float
    {
        if ($listaTotal <= 0) {
            return 0.0;
        }

        return $vendidoTotal * $listaGrupo / $listaTotal;
    }

    // Redondea varias partes de un mismo tour sin que la suma gane o pierda
    // céntimos (caso real 24-oct-2026: 101.615 + 48.385 daban 101.62 + 48.39 =
    // 150.01). Se redondea el total y la última parte se lleva la diferencia.
    // Recibe y devuelve las partes en el mismo orden y con las mismas claves.
    public static function repartirCentavos(array $exactas): array
    {
        if ($exactas === []) {
            return [];
        }

        $total = round(array_sum($exactas), 2);
        $claves = array_keys($exactas);
        $ultima = array_pop($claves);

        $redondeadas = [];
        foreach ($claves as $clave) {
            $redondeadas[$clave] = round($exactas[$clave], 2);
        }
        $redondeadas[$ultima] = round($total - array_sum($redondeadas), 2);

        return $redondeadas;
    }

    /**
     * @return array{adulto_nac: int, nino_nac: int, adulto_ext: int, nino_ext: int, infante: int}
     */
    public function conteo(int $cotizacionId): array
    {
        $pasajeros = CotizacionPasajero::where('cotizacion_id', $cotizacionId)->get();
        $ext = fn (CotizacionPasajero $p) => $p->residencia === CotizacionPasajero::RESIDENCIA_EXTRANJERO;

        return [
            'adulto_nac' => $pasajeros->filter(fn ($p) => $p->tipo_pax === 'adulto' && ! $ext($p))->count(),
            'nino_nac' => $pasajeros->filter(fn ($p) => $p->tipo_pax === 'nino' && ! $ext($p))->count(),
            'adulto_ext' => $pasajeros->filter(fn ($p) => $p->tipo_pax === 'adulto' && $ext($p))->count(),
            'nino_ext' => $pasajeros->filter(fn ($p) => $p->tipo_pax === 'nino' && $ext($p))->count(),
            'infante' => $pasajeros->where('tipo_pax', 'infante')->count(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Recalcular (pasajeros, precio a mano, temporada)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Agrupa las líneas de tour de una alternativa por unidad de cálculo: un
     * combo entero (su descuento se reparte entre sus tours) o una carga de
     * tour suelto.
     *
     * @return Collection<string, Collection<int, AlternativaItem>>
     */
    public function unidades(Collection $items): Collection
    {
        return $items->filter(fn (AlternativaItem $i) => $i->esLineaTour() && $i->carga_tour)
            ->groupBy(fn (AlternativaItem $i) => $i->carga_combo ?? $i->carga_tour);
    }

    /**
     * Reprecia una unidad (combo o tour) con los pasajeros actuales, con los
     * mismos precios y temporada ya cotizados. Con precio a mano no toca el
     * precio (solo costo y desglose).
     *
     * @return array{cambio: bool, precio_manual: bool}
     */
    public function recalcularUnidad(Collection $lineas, Alternativa $alternativa): array
    {
        [$specs, $combo] = $this->specsDeLineas($lineas);
        $calculo = $this->calcular(array_column($specs, 'spec'), $this->conteo($alternativa->cotizacion_id), $combo);
        $precioManual = $lineas->contains(fn (AlternativaItem $i) => $i->precio_manual);
        $cambio = false;

        foreach ($specs as $t => ['carga' => $carga]) {
            foreach ($lineas->where('carga_tour', $carga) as $linea) {
                $nuevo = $calculo[$t]['lineas'][(int) ($linea->tour_linea['grupo'] ?? 0)] ?? null;
                if ($nuevo === null) {
                    continue;
                }

                $datos = ['costo_snapshot' => $nuevo['costo'], 'desglose_costo_snapshot' => $nuevo['desglose']];
                if (! $precioManual) {
                    $datos['precio_venta_snapshot'] = $nuevo['precio'];
                    $datos['precio_convertido'] = round(
                        $this->convertir($nuevo['precio'], $linea->moneda_costo, $alternativa) * (1 - (float) ($linea->descuento_pct ?? 0) / 100),
                        2
                    );
                }

                $antes = [round((float) $linea->costo_snapshot, 2), round((float) $linea->precio_venta_snapshot, 2)];
                $linea->update($datos);
                $cambio = $cambio || $antes !== [round((float) $linea->costo_snapshot, 2), round((float) $linea->precio_venta_snapshot, 2)];
            }
        }

        return ['cambio' => $cambio, 'precio_manual' => $precioManual];
    }

    /**
     * El vendedor fija el precio total de una carga (en la moneda de la
     * cotización): se reparte entre sus líneas en la misma proporción que ya
     * tenían (mismo reparto por IGV) y queda marcado como precio a mano.
     */
    public function fijarPrecio(Collection $lineas, Alternativa $alternativa, float $precioTotal): void
    {
        $lineas = $lineas->sortBy('id')->values();
        $listas = $lineas->map(fn (AlternativaItem $i) => $this->convertir((float) $i->precio_venta_snapshot, $i->moneda_costo, $alternativa))->all();
        $partes = self::repartir($precioTotal, $listas);

        foreach ($lineas as $k => $linea) {
            $lista = $listas[$k];
            $linea->update([
                'precio_convertido' => $partes[$k],
                'descuento_pct' => $lista > 0 ? round((1 - $partes[$k] / $lista) * 100, 4) : 0,
                'precio_manual' => true,
            ]);
        }
    }

    /**
     * Líneas de tour cuya temporada ya no es la del día del tour (cambió la
     * fecha de la cotización). Solo informa: nunca recalcula sola (§3.12).
     *
     * @return array<int, array<string, mixed>>
     */
    public function temporadasDesactualizadas(Alternativa $alternativa): array
    {
        $fechaDesde = $alternativa->cotizacion?->fecha_viaje_desde;
        $avisos = [];

        foreach ($alternativa->items()->where('origen_tipo', AlternativaItem::ORIGEN_TOUR)->with('tourOrigen')->get()->groupBy('carga_tour') as $carga => $lineas) {
            $linea = $lineas->first();
            $tour = $linea->tourOrigen;
            if (! $tour) {
                continue;
            }

            $fecha = $this->fechaDelDia($fechaDesde, (int) $linea->dia_referencial);
            $nueva = $this->temporadaResolver->precioParaFecha($tour, $fecha)['ocurrencia'];
            $sinFechaAntes = ($linea->tour_linea['fecha_tour'] ?? null) === null;

            if ($nueva?->temporada_id === $linea->temporada_id && $sinFechaAntes === ($fecha === null)) {
                continue;
            }

            $avisos[] = [
                'carga_tour' => $carga,
                'tour_nombre' => $tour->nombre,
                'dia_referencial' => $linea->dia_referencial,
                'temporada_actual' => $linea->tour_linea['temporada_nombre'] ?? null,
                'temporada_nueva' => $nueva?->temporada?->nombre,
                'precio_manual' => $lineas->contains('precio_manual', true),
            ];
        }

        return $avisos;
    }

    /**
     * "Recalcular precios por temporada": relee el catálogo con la fecha
     * actual de cada tour. Respeta el precio a mano (se informa, no se pisa)
     * y no toca un tour cuyo Incluye cambió de grupos de IGV.
     *
     * @return array{recalculadas: int, omitidas: array<int, string>}
     */
    public function recalcularTemporadas(Alternativa $alternativa): array
    {
        $fechaDesde = $alternativa->cotizacion?->fecha_viaje_desde;
        $recalculadas = 0;
        $omitidas = [];
        $items = $alternativa->items()->where('origen_tipo', AlternativaItem::ORIGEN_TOUR)->with('tourOrigen')->get();

        foreach ($this->unidades($items) as $lineas) {
            $nombre = $lineas->first()->tour_linea['combo']['nombre'] ?? $lineas->first()->tourOrigen?->nombre ?? 'Tour';

            if ($lineas->contains('precio_manual', true)) {
                $omitidas[] = "«{$nombre}»: tiene el precio cambiado a mano.";

                continue;
            }

            try {
                $nuevosSpecs = [];
                foreach ($lineas->groupBy('carga_tour') as $carga => $deLaCarga) {
                    $primera = $deLaCarga->first();
                    if (! $primera->tourOrigen) {
                        throw new TourLineaException('el tour ya no existe en el catálogo.');
                    }
                    $spec = $this->specDelTour($primera->tourOrigen, $this->fechaDelDia($fechaDesde, (int) $primera->dia_referencial));
                    if (count($spec['grupos']) !== $deLaCarga->count()) {
                        throw new TourLineaException('el Incluye del tour cambió sus tipos de IGV: quítalo y cárgalo de nuevo.');
                    }
                    $nuevosSpecs[$carga] = $spec + ['dia' => $primera->dia_referencial];
                }
            } catch (TourLineaException $e) {
                $omitidas[] = "«{$nombre}»: ".$e->getMessage();

                continue;
            }

            $combo = $lineas->first()->tour_linea['combo'] ?? null;
            foreach ($lineas as $linea) {
                $spec = $nuevosSpecs[$linea->carga_tour];
                $linea->update([
                    'temporada_id' => $spec['temporada_id'],
                    'tour_linea' => $this->datosLinea($spec, (int) ($linea->tour_linea['grupo'] ?? 0), $combo),
                ]);
            }

            $this->recalcularUnidad($lineas->map->fresh(), $alternativa);
            $recalculadas++;
        }

        return ['recalculadas' => $recalculadas, 'omitidas' => $omitidas];
    }

    /**
     * Para el aviso de reprogramar una reserva ya aceptada (§3.12): el precio
     * NO cambia, solo se informa qué tours quedaron en otra temporada.
     *
     * @param  Collection<int, \App\Models\AgenciaViajes\ReservaItem>  $reservaItems
     * @return array<int, string>
     */
    public function avisosTemporadaReprogramacion(Collection $reservaItems): array
    {
        $avisos = [];

        foreach ($reservaItems as $reservaItem) {
            $item = $reservaItem->alternativaItem;
            if (! $item?->esLineaTour() || ! $item->tourOrigen || ! $reservaItem->fecha) {
                continue;
            }

            $nueva = $this->temporadaResolver->precioParaFecha($item->tourOrigen, $reservaItem->fecha->toDateString())['ocurrencia'];
            if ($nueva?->temporada_id !== $item->temporada_id) {
                $avisos[$item->carga_tour] = "«{$item->tourOrigen->nombre}» queda en otra temporada ("
                    .($nueva?->temporada?->nombre ?? 'precio base').'): el precio vendido no cambia.';
            }
        }

        return array_values($avisos);
    }

    // ═══════════════════════════════════════════════════════════════
    // Apoyo
    // ═══════════════════════════════════════════════════════════════

    // De las líneas guardadas a specs (uno por carga_tour, en orden de
    // creación) + el combo. tour_linea guarda el spec completo.
    private function specsDeLineas(Collection $lineas): array
    {
        $specs = [];
        foreach ($lineas->sortBy('id')->groupBy('carga_tour') as $carga => $deLaCarga) {
            $datos = $deLaCarga->first()->tour_linea ?? [];
            $specs[] = ['carga' => $carga, 'spec' => [
                'precios' => $datos['precios'],
                'grupos' => $datos['grupos'],
            ]];
        }

        return [$specs, $lineas->first()->tour_linea['combo'] ?? null];
    }

    private function datosLinea(array $spec, int $grupo, ?array $combo): array
    {
        return [
            'tour_nombre' => $spec['tour_nombre'],
            'fecha_tour' => $spec['fecha_tour'],
            'temporada_nombre' => $spec['temporada_nombre'],
            'precios' => $spec['precios'],
            'grupos' => $spec['grupos'],
            'grupo' => $grupo,
            'n_grupos' => count($spec['grupos']),
            'combo' => $combo,
        ];
    }

    private function fechaDelDia($fechaDesde, int $dia): ?string
    {
        return $fechaDesde ? $fechaDesde->copy()->addDays(max(1, $dia) - 1)->toDateString() : null;
    }

    private function convertir(float $monto, string $moneda, Alternativa $alternativa): float
    {
        return $this->priceEngine->convertirMoneda($monto, $moneda, $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado);
    }
}
