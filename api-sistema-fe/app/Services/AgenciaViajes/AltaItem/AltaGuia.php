<?php

namespace App\Services\AgenciaViajes\AltaItem;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Services\HoraPeru;

// T-095 — origen_tipo=guia. Usa el mismo cálculo que ComboExplosionService::resolverCostoVentaItem()
// para guia_tarifa_id: costo_diario es un monto fijo del día, no se reparte por pasajero (igual que
// transporte privado u hotel), así que el modo_precio es siempre tarifa_fija.
class AltaGuia extends AltaItemBase
{
    public function crear(Alternativa $alternativa, array $datos): AlternativaItem
    {
        $validado = $this->validarReglas($datos, [
            'guia_tarifa_id' => 'required|integer|exists:guia_tarifas,id',
            'cantidad' => 'nullable|integer|min:1',
            'pax_incluidos' => 'nullable|array',
            'pax_incluidos.*' => 'integer|exists:cotizacion_pasajeros,id',
            'dia_referencial' => 'nullable|integer|min:1',
            'tip_afe_igv' => 'nullable|string|in:10,20,30',
            'destino_tributario' => 'nullable|string|in:amazonia,nacional,extranjero',
            'alternativa_destino_id' => ['nullable', 'integer', $alternativa->reglaDestinoId()],
        ]);

        $guiaTarifa = GuiaTarifa::findOrFail($validado['guia_tarifa_id']);

        // El selector ya excluye las tarifas retiradas, pero esto es lo que decide si un ítem nuevo
        // se puede crear: una pestaña vieja podría mandar una tarifa ya desactivada.
        if (! $guiaTarifa->activo) {
            throw new AltaItemException('Esta tarifa fue retirada del catálogo activo — no se puede usar en un ítem nuevo.');
        }

        // Una versión vieja (cerrada) o futura no se cotiza; hay que usar la vigente (T-028).
        if (! $guiaTarifa->estaVigente()) {
            throw new AltaItemException(
                $guiaTarifa->vigente_desde->toDateString() > HoraPeru::hoyTexto()
                    ? 'Esta tarifa todavía no está vigente (desde '.$guiaTarifa->vigente_desde->format('d/m/Y').').'
                    : 'Esta tarifa ya no está vigente: usa la versión actual.'
            );
        }

        $calculo = $this->priceEngine->calcular(
            (float) $guiaTarifa->costo_diario,
            [],
            $guiaTarifa->tipo_margen,
            (float) $guiaTarifa->margen_valor,
            null,
            null
        );

        $tratamientoTributario = ConfiguracionAgencia::completarTratamientoTributario($validado['tip_afe_igv'] ?? null, $validado['destino_tributario'] ?? null);

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'alternativa_destino_id' => $alternativa->destinoIdODefault($validado['alternativa_destino_id'] ?? null),
            'origen_tipo' => AlternativaItem::ORIGEN_GUIA,
            'guia_tarifa_id' => $guiaTarifa->id,
            'modo_precio' => 'tarifa_fija',
            'cantidad' => $validado['cantidad'] ?? 1,
            'pax_incluidos' => $validado['pax_incluidos'] ?? null,
            'moneda_costo' => $guiaTarifa->moneda,
            'costo_snapshot' => $calculo['costo_total'],
            'precio_venta_snapshot' => $calculo['venta_total'],
            'precio_convertido' => $this->convertir($calculo['venta_total'], $guiaTarifa->moneda, $alternativa),
            'dia_referencial' => $validado['dia_referencial'] ?? null,
            'tip_afe_igv' => $tratamientoTributario['tip_afe_igv'],
            'destino_tributario' => $tratamientoTributario['destino_tributario'],
        ]);

        $this->cerrar($alternativa);

        return $item;
    }
}
