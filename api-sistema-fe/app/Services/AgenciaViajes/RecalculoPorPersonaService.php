<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;

// Reprecia los ítems "por persona" de una cotización contra su lista actual de
// pasajeros. Antes vivía privado en CotizacionController (actualizarPasajeros());
// se extrajo en T-002 para que "Modificar reserva" (ModificacionReservaService)
// recalcule con exactamente el mismo código cuando la copia tiene otra cantidad
// de pasajeros que la versión vendida. Sin cambios de comportamiento.
class RecalculoPorPersonaService
{
    public function __construct(
        private PriceEngineService $priceEngine,
        private TourLineaService $tourLinea,
    ) {
    }

    // Edad máxima de infante y de niño según la configuración de la agencia.
    public static function umbralesEdad(): array
    {
        $config = ConfiguracionAgencia::first();

        return [
            $config->edad_max_infante ?? 2,
            $config->edad_max_nino ?? 12,
        ];
    }

    public static function derivarTipoPax(int $edad, int $edadMaxInfante, int $edadMaxNino): string
    {
        if ($edad <= $edadMaxInfante) {
            return 'infante';
        }
        if ($edad <= $edadMaxNino) {
            return 'nino';
        }

        return 'adulto';
    }

    // Recorre las alternativas 'borrador'/'enviada' de la cotización y
    // repreciar cada ítem 'por_persona' contra la composición ACTUAL de
    // pasajeros. Devuelve [cantidad_recalculada, items_para_revisar].
    public function recalcular(Cotizacion $cotizacion): array
    {
        $itemsRecalculados = 0;
        $itemsParaRevisar = [];

        foreach ($cotizacion->alternativas as $alternativa) {
            if (! in_array($alternativa->estado, ['borrador', 'enviada'], true)) {
                continue;
            }

            $huboCambio = false;

            // Fila 13, fase 1b: la línea de tour se reprecia sola (precio y
            // costo por pasajero, misma temporada y mismo reparto por IGV ya
            // cotizados). Con precio a mano, solo costo: el precio se avisa.
            foreach ($this->tourLinea->unidades($alternativa->items()->get()) as $lineas) {
                $resultado = $this->tourLinea->recalcularUnidad($lineas, $alternativa);
                if ($resultado['cambio']) {
                    $itemsRecalculados += $lineas->count();
                    $huboCambio = true;
                }
                if ($resultado['precio_manual']) {
                    $itemsParaRevisar[] = [
                        'alternativa_item_id' => $lineas->first()->id,
                        'alternativa_id' => $alternativa->id,
                        'alternativa_nombre' => $alternativa->nombre,
                        'motivo' => 'El tour «'.($lineas->first()->tour_linea['combo']['nombre'] ?? $lineas->first()->tour_linea['tour_nombre'] ?? 'Tour').'» tiene el precio cambiado a mano: se actualizó el costo, revisa el precio.',
                    ];
                }
            }

            foreach ($alternativa->items()->where('modo_precio', 'por_persona')->where('origen_tipo', '!=', AlternativaItem::ORIGEN_TOUR)->get() as $item) {
                // Único caso de alta confianza: origen_tipo=proveedor con
                // una proveedor_tarifa real detrás — mismo cálculo exacto
                // que AlternativaItemController::crearItemProveedor().
                if ($item->origen_tipo !== AlternativaItem::ORIGEN_PROVEEDOR || ! $item->proveedor_tarifa_id) {
                    $itemsParaRevisar[] = [
                        'alternativa_item_id' => $item->id,
                        'alternativa_id' => $alternativa->id,
                        'alternativa_nombre' => $alternativa->nombre,
                        'motivo' => $item->origen_tipo === AlternativaItem::ORIGEN_PROVEEDOR
                            ? 'Ítem "por persona" sin proveedor_tarifa real detrás — no se puede repreciar automáticamente.'
                            : 'Ítem "por persona" de origen ' . $item->origen_tipo . ' — se debe repreciar manualmente.',
                    ];
                    continue;
                }

                $tarifa = $item->proveedorTarifa;
                if (! $tarifa) {
                    $itemsParaRevisar[] = [
                        'alternativa_item_id' => $item->id,
                        'alternativa_id' => $alternativa->id,
                        'alternativa_nombre' => $alternativa->nombre,
                        'motivo' => 'La tarifa de proveedor original de este ítem ya no existe — no se puede repreciar automáticamente.',
                    ];
                    continue;
                }

                if (is_array($item->pax_incluidos) && count($item->pax_incluidos) === 0) {
                    $itemsParaRevisar[] = [
                        'alternativa_item_id' => $item->id,
                        'alternativa_id' => $alternativa->id,
                        'alternativa_nombre' => $alternativa->nombre,
                        'motivo' => 'Todos los pasajeros asignados a este ítem fueron eliminados — revisar a quién aplica ahora.',
                    ];
                    continue;
                }

                $conteo = $this->contarPasajerosPorTipo($alternativa->cotizacion_id, $item->pax_incluidos);
                $nuevoPrecioVentaSnapshot = round(
                    $conteo['adulto'] * (float) $tarifa->precio_venta_adulto
                        + $conteo['nino'] * (float) ($tarifa->precio_venta_nino ?? 0)
                        + $conteo['infante'] * (float) ($tarifa->precio_venta_infante ?? 0),
                    2
                );

                $precioListaConvertido = $this->priceEngine->convertirMoneda(
                    $nuevoPrecioVentaSnapshot,
                    $item->moneda_costo,
                    $alternativa->moneda_cotizacion,
                    (float) $alternativa->tipo_cambio_aplicado
                );

                // Preserva cualquier descuento manual ya aplicado sobre el
                // ítem (AlternativaItemController::update()) — recalcular
                // el precio de lista no debe borrar en silencio una
                // negociación ya hecha con el cliente.
                $descuentoPct = (float) ($item->descuento_pct ?? 0);
                $nuevoPrecioConvertido = round($precioListaConvertido * (1 - $descuentoPct / 100), 2);

                // E2 (fila 13, fase 1b; decisión del usuario 14-oct-2026):
                // el costo también sigue a los pasajeros — total de la línea,
                // sin infantes. Antes solo se actualizaba la venta y el margen
                // quedaba inflado o desinflado.
                $nuevoCostoSnapshot = round((float) $tarifa->precio_costo * ($conteo['adulto'] + $conteo['nino']), 2);

                // precio_venta_snapshot es decimal:2 (Eloquent lo entrega
                // como string) — comparar como float con round(2) en ambos
                // lados evita falsos "cambió" por ruido de precisión
                // binaria, y evita reescribir el ítem/recalcular el total
                // de la alternativa cuando el precio en realidad no varió.
                if (round((float) $item->precio_venta_snapshot, 2) !== $nuevoPrecioVentaSnapshot
                    || round((float) $item->costo_snapshot, 2) !== $nuevoCostoSnapshot) {
                    $item->update([
                        'precio_venta_snapshot' => $nuevoPrecioVentaSnapshot,
                        'precio_convertido' => $nuevoPrecioConvertido,
                        'costo_snapshot' => $nuevoCostoSnapshot,
                    ]);
                    $itemsRecalculados++;
                    $huboCambio = true;
                }
            }

            // calcularTotalEfectivo() (fila 13, fase 1b): la suma plana de
            // antes contaba todas las opciones de un grupo de hoteles, no
            // solo la elegida (o la más barata si está abierto), a
            // diferencia del resto de los puntos que guardan el total.
            if ($huboCambio) {
                $alternativa->update(['total' => AlternativaItem::calcularTotalEfectivo($alternativa->items()->get())['total']]);
            }
        }

        return [$itemsRecalculados, $itemsParaRevisar];
    }

    private function contarPasajerosPorTipo(int $cotizacionId, ?array $paxIncluidosIds): array
    {
        $query = CotizacionPasajero::where('cotizacion_id', $cotizacionId);
        if ($paxIncluidosIds) {
            $query->whereIn('id', $paxIncluidosIds);
        }
        $pasajeros = $query->get();

        return [
            'adulto' => $pasajeros->where('tipo_pax', 'adulto')->count(),
            'nino' => $pasajeros->where('tipo_pax', 'nino')->count(),
            'infante' => $pasajeros->where('tipo_pax', 'infante')->count(),
        ];
    }
}
