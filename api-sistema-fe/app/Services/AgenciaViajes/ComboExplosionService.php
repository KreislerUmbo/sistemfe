<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\PaquetePlantilla;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

// Resuelve un paquete_combo contra los tours_simple reales que incluye —
// Sesión 11b4 (plan-modulo-cotizaciones-reservas.md §3.7, puntos 3-5 del
// diseño nuevo). Tres responsabilidades relacionadas en un solo service para
// no duplicar la resolución de "ítems de un tour_simple → costo/venta
// reales":
//   - totalesTour()/totalesCombo(): cálculo de precio en vivo (usado por
//     precio_venta_final calculado y por la validación de margen mínimo).
//   - explotarItems(): para cargar un combo en el cotizador — cada línea de
//     cada tour incluido, resuelta contra su proveedor_tarifa/guia_tarifa
//     real, tagueada con tour_origen_id. CRÍTICO: líneas de guía NO se
//     deduplican entre tours del mismo combo (cada tour puede terminar con
//     un guía operativo distinto, son líneas independientes por diseño).
//   - itinerarioDerivado(): concatena el itinerario real de cada tour
//     incluido con el día_relativo desplazado (offset), sin persistir nada.
//
// Autocontenido: no depende de ningún mecanismo de frontend (11b3 —
// "cargar desde plantilla" en el cotizador — no está construido todavía).
// "venta" de cada ítem usa el tier adulto (proveedor_tarifa.precio_venta_adulto)
// como referencia — mismo criterio que paquetes_plantilla.precio_venta_final
// ya usa como precio "desde" único, sin desglosar por tipo de pax.
//
// Sesión 11o: explotarItems()/explotarTourSimple() (vía explotarUnTour())
// devuelven ADEMÁS `modalidad` y los precios crudos (precio_costo/
// precio_venta_adulto/nino/infante) de cada ítem — quien cargue esto en una
// cotización real (AlternativaItemController::desdePlantilla()) decide ahí
// si multiplica por pasajero real (modalidad='compartido') o usa la tarifa
// plana (modalidad='privado'). totalesTour()/totalesCombo() siguen sin
// cambios: son un precio "desde" de catálogo, no dependen de ninguna
// cotización real.
class ComboExplosionService
{
    // N+1 fix (sesión pendiente, ver arquitectura-multitenant-backend.md /
    // plan general): toursDelCombo() se llama por separado desde
    // totalesCombo(), itinerarioDerivado() Y directo desde el controller
    // para el MISMO combo en la misma request (ver
    // PaquetePlantillaController::show()) — sin esto, la query base se repite
    // 3 veces. Memoizado por combo->id: el service se resuelve una vez por
    // request (sin binding singleton), así que esto nunca cruza requests.
    private array $toursDelComboCache = [];

    public function __construct(private PriceEngineService $priceEngine)
    {
    }

    /**
     * @return array{costo_total: float, venta_total: float}
     */
    public function totalesTour(PaquetePlantilla $tour): array
    {
        $costoTotal = 0.0;
        $ventaTotal = 0.0;

        // N+1 fix: si totalesCombo() ya precargó 'items.proveedorTarifa'/
        // 'items.guiaTarifa' sobre este tour (ver ahí), usa esa colección en
        // vez de disparar una query nueva — sigue funcionando igual para un
        // tour_simple suelto (nunca llega acá precargado), que cae al query
        // normal de siempre.
        $items = $tour->relationLoaded('items')
            ? $tour->items
            : $tour->items()->with(['proveedorTarifa', 'guiaTarifa'])->get();

        foreach ($items as $item) {
            [$costo, $venta] = $this->resolverCostoVentaItem($item);
            $costoTotal += $costo;
            $ventaTotal += $venta;
        }

        return ['costo_total' => round($costoTotal, 2), 'venta_total' => round($ventaTotal, 2)];
    }

    /**
     * @return array{
     *     costo_total_combo: float,
     *     venta_bruta_combo: float,
     *     venta_neta_combo: float,
     *     descuento_aplicado: float,
     *     margen_resultante_pct: float|null,
     *     componentes_inactivos: array<int, array{id: int, nombre: string}>
     * }
     */
    public function totalesCombo(PaquetePlantilla $combo): array
    {
        $tours = $this->toursDelCombo($combo);

        // N+1 fix: precarga items+tarifas+itinerario de TODOS los tours del
        // combo en un puñado de queries (batched vía whereIn, no una por tour).
        // El guard relationLoaded() evita recargar si esta MISMA colección
        // (memoizada arriba) ya viene cargada de una llamada anterior a
        // totalesCombo()/itinerarioDerivado() para este combo en la misma
        // request.
        if ($tours->isNotEmpty() && ! $tours->first()->relationLoaded('items')) {
            // toursDelCombo() pluckea 'paquetePlantillaHijo' desde una
            // relación — Eloquent\Collection::pluck() llama a toBase()
            // adentro a propósito, así que $tours es un Support\Collection
            // plano (sin load()), no un Eloquent\Collection, pese a
            // contener Models. Envolver acá no clona nada: son los MISMOS
            // objetos Model por referencia, así que cargar relaciones sobre
            // el wrapper también las deja visibles en $tours/el cache.
            EloquentCollection::make($tours)->load([
                'items.proveedorTarifa',
                'items.guiaTarifa',
                'paqueteItinerario' => fn ($q) => $q->orderBy('dia_relativo')->orderBy('orden'),
            ]);
        }

        // Punto 10 del diseño: un tour_simple desactivado a la fuerza
        // mientras seguía incluido en este combo se excluye del cálculo de
        // precio (nunca rompe el total en silencio), pero se reporta acá
        // para que el frontend muestre la advertencia visible.
        $tourTotales = $tours->filter(fn (PaquetePlantilla $tour) => $tour->activo)
            ->map(fn (PaquetePlantilla $tour) => $this->totalesTour($tour))
            ->all();

        // Fix ítems sueltos del combo (2026-08-18): un ítem agregado directo
        // al combo (proveedor_tarifa_id/guia_tarifa_id sobre el propio
        // paquete_plantilla_items, sin envolverlo en un tour-hijo) quedaba
        // completamente fuera de este cálculo — toursDelCombo() solo mira
        // paquete_plantilla_hijo_id. ProveedorTarifa/GuiaTarifa no tienen
        // columna 'activo' propia (confirmado, no hay equivalente al guard
        // de componentes_inactivos de arriba) — no hay nada que excluir acá,
        // solo sumar.
        $itemsSueltos = $this->itemsSueltosDelCombo($combo);
        if ($itemsSueltos->isNotEmpty()) {
            $costoSueltos = 0.0;
            $ventaSueltos = 0.0;

            foreach ($itemsSueltos as $item) {
                [$costo, $venta] = $this->resolverCostoVentaItem($item);
                $costoSueltos += $costo;
                $ventaSueltos += $venta;
            }

            $tourTotales[] = ['costo_total' => round($costoSueltos, 2), 'venta_total' => round($ventaSueltos, 2)];
        }

        $resultado = $this->priceEngine->calcularCombo(
            $tourTotales,
            $combo->descuento_tipo,
            $combo->descuento_valor,
            $combo->ajuste_redondeo !== null ? (float) $combo->ajuste_redondeo : null
        );

        $resultado['componentes_inactivos'] = $tours->filter(fn (PaquetePlantilla $tour) => ! $tour->activo)
            ->map(fn (PaquetePlantilla $tour) => ['id' => $tour->id, 'nombre' => $tour->nombre])
            ->values()
            ->all();

        // Sesión 11m: un tour-hijo ACTIVO sin Incluye/Itinerario cargado no
        // rompe el cálculo (suma 0), pero rompía cotizaciones en silencio —
        // avisado acá con el mismo shape que componentes_inactivos. Un tour
        // ya inactivo no necesita este aviso aparte (ya tiene el suyo).
        $toursActivos = $tours->filter(fn (PaquetePlantilla $tour) => $tour->activo);

        $resultado['componentes_sin_incluye'] = $toursActivos->filter(fn (PaquetePlantilla $tour) => $tour->items->count() === 0)
            ->map(fn (PaquetePlantilla $tour) => ['id' => $tour->id, 'nombre' => $tour->nombre])
            ->values()
            ->all();

        $resultado['componentes_sin_itinerario'] = $toursActivos->filter(fn (PaquetePlantilla $tour) => $tour->paqueteItinerario->count() === 0)
            ->map(fn (PaquetePlantilla $tour) => ['id' => $tour->id, 'nombre' => $tour->nombre])
            ->values()
            ->all();

        return $resultado;
    }

    // Tours-hijo de un combo, en el orden en que están cargados
    // (paquete_plantilla_items.orden — reusado con el doble propósito
    // documentado en la migración de esta sesión).
    public function toursDelCombo(PaquetePlantilla $combo): Collection
    {
        if (isset($this->toursDelComboCache[$combo->id])) {
            return $this->toursDelComboCache[$combo->id];
        }

        $tours = $combo->items()
            ->whereNotNull('paquete_plantilla_hijo_id')
            ->orderBy('orden')
            ->with('paquetePlantillaHijo')
            ->get()
            ->pluck('paquetePlantillaHijo')
            ->filter()
            ->values();

        return $this->toursDelComboCache[$combo->id] = $tours;
    }

    // Ítems agregados DIRECTO a un paquete_combo (proveedor_tarifa_id/
    // guia_tarifa_id sobre el propio combo, sin envolverlos en un tour-hijo)
    // — el frontend ya los llama "ítems sueltos" (paquetes/detalle.vue,
    // computed itemsSueltos). Antes del fix de 2026-08-18 nada del backend
    // los leía aparte de la pantalla de detalle: ni totalesCombo() ni
    // explotarItems()/desdePlantilla() los tocaban.
    public function itemsSueltosDelCombo(PaquetePlantilla $combo): EloquentCollection
    {
        return $combo->items()
            ->whereNull('paquete_plantilla_hijo_id')
            ->with(['proveedorTarifa', 'guiaTarifa'])
            ->orderBy('orden')
            ->get();
    }

    /**
     * Cada ítem atómico de cada tour incluido MÁS los ítems sueltos del
     * propio combo, resuelto contra su tarifa real, tagueado con de qué
     * tour vino (null si es un ítem suelto del combo, no de un tour-hijo).
     * No persiste nada — arma el array listo para alimentar
     * AlternativaItem::create() en bucle.
     *
     * @return array<int, array{tour_origen_id: int|null, proveedor_tarifa_id: int|null, guia_tarifa_id: int|null, modalidad: string|null, costo: float, venta: float, precio_costo: float|null, precio_venta_adulto: float|null, precio_venta_nino: float|null, precio_venta_infante: float|null}>
     */
    public function explotarItems(PaquetePlantilla $combo): array
    {
        $resultado = [];

        foreach ($this->toursDelCombo($combo) as $tour) {
            $resultado = array_merge($resultado, $this->explotarUnTour($tour));
        }

        return array_merge($resultado, $this->explotarItemsSueltos($combo));
    }

    // Mismos ítems que itemsSueltosDelCombo(), ya resueltos contra su
    // tarifa real (costo/venta) — tour_origen_id siempre null (no
    // pertenecen a ningún tour-hijo). Separado de explotarItems() para que
    // AlternativaItemController::desdePlantilla() pueda pedir SOLO estos sin
    // recorrer toursDelCombo()/explotarUnTour() de nuevo (ya los procesa en
    // su propio bucle, con offset de día por tour).
    //
    // @return array<int, array{tour_origen_id: null, proveedor_tarifa_id: int|null, guia_tarifa_id: int|null, modalidad: string|null, costo: float, venta: float, precio_costo: float|null, precio_venta_adulto: float|null, precio_venta_nino: float|null, precio_venta_infante: float|null}>
    public function explotarItemsSueltos(PaquetePlantilla $combo): array
    {
        return $this->explotarColeccionItems($this->itemsSueltosDelCombo($combo), null);
    }

    // Sesión 11b3 — cargar un tour_simple SUELTO (no dentro de un combo) en
    // el cotizador. Mismo cuerpo que un tour dentro de explotarItems(), acá
    // expuesto para un tour_simple standalone — tour_origen_id apunta al
    // propio tour (no a un padre), mismo criterio que ya usa el resto del
    // vertical para "de dónde vino este ítem".
    //
    // @return array<int, array{tour_origen_id: int, proveedor_tarifa_id: int|null, guia_tarifa_id: int|null, modalidad: string|null, costo: float, venta: float, precio_costo: float|null, precio_venta_adulto: float|null, precio_venta_nino: float|null, precio_venta_infante: float|null}>
    public function explotarTourSimple(PaquetePlantilla $tour): array
    {
        return $this->explotarUnTour($tour);
    }

    // @return array<int, array{tour_origen_id: int, proveedor_tarifa_id: int|null, guia_tarifa_id: int|null, modalidad: string|null, costo: float, venta: float, precio_costo: float|null, precio_venta_adulto: float|null, precio_venta_nino: float|null, precio_venta_infante: float|null}>
    private function explotarUnTour(PaquetePlantilla $tour): array
    {
        $items = $tour->items()->with(['proveedorTarifa', 'guiaTarifa'])->orderBy('orden')->get();

        return $this->explotarColeccionItems($items, $tour->id);
    }

    // Sesión 11o — devuelve además `modalidad` y los 3 precios crudos de la
    // tarifa (precio_costo/precio_venta_adulto/nino/infante), sin pre-calcular
    // un único "venta" — la decisión de cómo multiplicar (tarifa plana adulto
    // vs. por-pasajero-real según modalidad='compartido') pasa a vivir en
    // AlternativaItemController::desdePlantilla(), que sí conoce la
    // cotización real (cuántos adultos/niños/infantes viajan). `costo`/`venta`
    // se mantienen con el criterio VIEJO (tier adulto plano) solo por
    // compatibilidad de forma — nada más los sigue leyendo hoy;
    // totalesTour()/totalesCombo() (precio "desde" del catálogo) usan su
    // PROPIO resolverCostoVentaItem(), sin cambios, no dependen de este método.
    //
    // Extraído de explotarUnTour() (fix ítems sueltos, 2026-08-18) para
    // reusar la misma resolución de línea con los ítems sueltos de un combo
    // — que no pertenecen a ningún tour, por eso $tourOrigenId es nullable acá.
    //
    // @return array<int, array{tour_origen_id: int|null, proveedor_tarifa_id: int|null, guia_tarifa_id: int|null, modalidad: string|null, costo: float, venta: float, precio_costo: float|null, precio_venta_adulto: float|null, precio_venta_nino: float|null, precio_venta_infante: float|null}>
    private function explotarColeccionItems(iterable $items, ?int $tourOrigenId): array
    {
        $resultado = [];

        foreach ($items as $item) {
            [$costo, $venta] = $this->resolverCostoVentaItem($item);
            $tarifa = $item->proveedor_tarifa_id ? $item->proveedorTarifa : null;

            $resultado[] = [
                'tour_origen_id' => $tourOrigenId,
                'proveedor_tarifa_id' => $item->proveedor_tarifa_id,
                'guia_tarifa_id' => $item->guia_tarifa_id,
                'modalidad' => $tarifa?->modalidad,
                'costo' => $costo,
                'venta' => $venta,
                'precio_costo' => $tarifa ? (float) $tarifa->precio_costo : null,
                'precio_venta_adulto' => $tarifa ? (float) $tarifa->precio_venta_adulto : null,
                'precio_venta_nino' => $tarifa ? (float) ($tarifa->precio_venta_nino ?? 0) : null,
                'precio_venta_infante' => $tarifa ? (float) ($tarifa->precio_venta_infante ?? 0) : null,
            ];
        }

        return $resultado;
    }

    /**
     * Itinerario derivado del combo (punto 5 del diseño): concatena el
     * itinerario real de cada tour incluido, con dia_relativo desplazado
     * según el orden de los tours en el combo. Se recalcula cada vez que se
     * pide (PDF, pantalla de detalle) — no hay tabla nueva ni persistencia.
     * Fuera de alcance: combos que mezclen tours-hijo con ítems sueltos en
     * la misma secuencia de días (documentado como pendiente en el diseño
     * original, no resuelto acá).
     *
     * @return array<int, array{dia_relativo: int, hora: string|null, orden: int|null, destino_atractivo_id: int|null, descripcion: string, tour_origen_id: int, tour_origen_nombre: string}>
     */
    public function itinerarioDerivado(PaquetePlantilla $combo): array
    {
        $itinerario = [];
        $offsetDia = 0;

        $tours = $this->toursDelCombo($combo);

        // N+1 fix: mismo criterio que totalesCombo() — si esta colección
        // (memoizada por toursDelCombo()) ya viene con paqueteItinerario
        // cargado de una llamada anterior en la misma request, lo reusa. El
        // orderBy queda igual acá y en totalesCombo() a propósito: cualquiera
        // de las dos que corra primero deja la relación bien ordenada para
        // la otra.
        if ($tours->isNotEmpty() && ! $tours->first()->relationLoaded('paqueteItinerario')) {
            // Mismo motivo que en totalesCombo(): $tours no es un
            // Eloquent\Collection, envolver para poder usar load().
            EloquentCollection::make($tours)->load(['paqueteItinerario' => fn ($q) => $q->orderBy('dia_relativo')->orderBy('orden')]);
        }

        foreach ($tours as $tour) {
            $pasos = $tour->paqueteItinerario;
            $maxDiaDelTour = 0;

            foreach ($pasos as $paso) {
                $itinerario[] = [
                    'dia_relativo' => $offsetDia + $paso->dia_relativo,
                    'hora' => $paso->hora,
                    'orden' => $paso->orden,
                    'destino_atractivo_id' => $paso->destino_atractivo_id,
                    'descripcion' => $paso->descripcion,
                    'tour_origen_id' => $tour->id,
                    'tour_origen_nombre' => $tour->nombre,
                ];

                $maxDiaDelTour = max($maxDiaDelTour, $paso->dia_relativo);
            }

            $offsetDia += $maxDiaDelTour;
        }

        return $itinerario;
    }

    /**
     * @return array{0: float, 1: float} [costo, venta]
     */
    private function resolverCostoVentaItem(\App\Models\AgenciaViajes\PaquetePlantillaItem $item): array
    {
        if ($item->proveedor_tarifa_id) {
            return [
                (float) $item->proveedorTarifa->precio_costo,
                (float) $item->proveedorTarifa->precio_venta_adulto,
            ];
        }

        if ($item->guia_tarifa_id) {
            $guiaTarifa = $item->guiaTarifa;
            $calculo = $this->priceEngine->calcular(
                (float) $guiaTarifa->costo_diario,
                [],
                $guiaTarifa->tipo_margen,
                (float) $guiaTarifa->margen_valor,
                null,
                null
            );

            return [$calculo['costo_total'], $calculo['venta_total']];
        }

        // Ítem con paquete_plantilla_hijo_id dentro de un tour_simple: no
        // debería ocurrir nunca (bloqueado por ComboValidationService::
        // validarProfundidad()), pero si un dato viejo/inconsistente llega
        // hasta acá, no se sube en silencio — cuenta en 0, no se inventa un
        // precio.
        return [0.0, 0.0];
    }
}
