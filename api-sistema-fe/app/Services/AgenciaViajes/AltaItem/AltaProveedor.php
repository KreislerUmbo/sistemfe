<?php

namespace App\Services\AgenciaViajes\AltaItem;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\ProveedorTarifa;

// T-095 — origen_tipo=proveedor. El precio sale de una de tres fuentes:
//  1. una tarifa real (proveedor_tarifa_id): rechaza la retirada o fuera de vigencia, calcula con
//     PriceEngineService::ventaYCostoDeTarifa() y, si es tarifa_fija, suma la cama adicional;
//  2. un hotel ad-hoc local (opcion_hotel_tarifa_id): siempre por habitación, sin cama adicional
//     (esa mecánica depende del detalle de alojamiento del proveedor, que un ad-hoc no tiene);
//  3. un precio de referencia escrito a mano (ni tarifa ni hotel): el costo es 0 si no se escribió.
// Con tarifa real o hotel ad-hoc el tratamiento tributario sale de ellos; solo en el caso 3 manda lo
// escrito (y lo que falte, el default de la agencia).
class AltaProveedor extends AltaItemBase
{
    public function crear(Alternativa $alternativa, array $datos): AlternativaItem
    {
        $validado = $this->validarReglas($datos, [
            'proveedor_tarifa_id' => 'nullable|integer|exists:proveedor_tarifas,id',
            'opcion_hotel_tarifa_id' => 'nullable|integer|exists:opciones_hotel_tarifas,id',
            'modo_precio' => 'required|in:por_persona,tarifa_fija',
            'cantidad' => 'nullable|integer|min:1',
            'pax_incluidos' => 'nullable|array',
            'pax_incluidos.*' => 'integer|exists:cotizacion_pasajeros,id',
            // Solo sin proveedor_tarifa_id ni opcion_hotel_tarifa_id: precio de referencia.
            'moneda_costo' => 'required_without_all:proveedor_tarifa_id,opcion_hotel_tarifa_id|nullable|in:PEN,USD',
            'precio_venta_snapshot' => 'required_without_all:proveedor_tarifa_id,opcion_hotel_tarifa_id|nullable|numeric|min:0',
            'costo_snapshot' => 'nullable|numeric|min:0',
            // Solo tiene efecto con precio de referencia: con tarifa real o hotel ad-hoc el tributario sale de ellos.
            'tip_afe_igv' => 'nullable|string|in:10,20,30',
            'destino_tributario' => 'nullable|string|in:amazonia,nacional,extranjero',
            'dia_referencial' => 'nullable|integer|min:1',
            // Camas adicionales necesarias (0 = ninguna: nunca se cobra de más por defecto). Solo tiene
            // efecto con modo_precio='tarifa_fija' y una tarifa de un proveedor con detalle de alojamiento.
            'camas_adicionales_nino' => 'nullable|integer|min:0',
            'alternativa_destino_id' => ['nullable', 'integer', $alternativa->reglaDestinoId()],
            // El picker de habitaciones genera el uuid una vez (cliente) y lo manda igual en cada llamada
            // de "Agregar N opciones como grupo" (AlternativaItem::agruparPorGrupoOpcion()). Las filas de
            // un grupo nuevo nacen sin elegir: resolverlo es una acción aparte.
            'grupo_opcion_id' => 'nullable|uuid',
        ]);

        $tarifa = ! empty($validado['proveedor_tarifa_id']) ? ProveedorTarifa::findOrFail($validado['proveedor_tarifa_id']) : null;
        $opcionHotelTarifa = ! empty($validado['opcion_hotel_tarifa_id']) ? OpcionHotelTarifa::findOrFail($validado['opcion_hotel_tarifa_id']) : null;
        $cantidad = $validado['cantidad'] ?? 1;
        $paxIncluidos = $validado['pax_incluidos'] ?? null;

        // El selector ya excluye las tarifas desactivadas, pero esto es lo que decide si un ítem nuevo se
        // puede crear (una pestaña vieja podría mandar una ya retirada). No aplica a ítems existentes: su
        // precio ya está congelado. El motivo (retirada / fuera de vigencia) vive en PriceEngineService,
        // compartido con la vista previa de Venta directa (T-045).
        $motivoNoUsable = $tarifa ? $this->priceEngine->motivoTarifaNoUsable($tarifa) : null;
        if ($motivoNoUsable) {
            throw new AltaItemException($motivoNoUsable);
        }

        if ($tarifa) {
            $monedaCosto = $tarifa->moneda;

            // Fórmula única en PriceEngineService (T-045; E2, fila 13: el costo por persona es el TOTAL
            // de la línea, sin infantes).
            $conteo = $validado['modo_precio'] === 'por_persona'
                ? $this->priceEngine->contarPasajerosPorTipo($alternativa->cotizacion_id, $paxIncluidos)
                : ['adulto' => 0, 'nino' => 0, 'infante' => 0];
            ['venta' => $precioVentaSnapshot, 'costo' => $costoSnapshot] = $this->priceEngine
                ->ventaYCostoDeTarifa($tarifa, $conteo, $validado['modo_precio']);

            if ($validado['modo_precio'] !== 'por_persona') {
                // tarifa_fija (hotel por habitación, transporte privado por vehículo): un solo precio, no
                // diferenciado por tipo de pasajero.
                $camasAdicionales = $validado['camas_adicionales_nino'] ?? 0;
                if ($camasAdicionales > 0) {
                    $cama = $this->camaAdicional($alternativa, $tarifa, $paxIncluidos, $camasAdicionales);

                    $costoSnapshot += $cama['costo'];
                    $precioVentaSnapshot += $cama['venta'];
                }
            }
        } elseif ($opcionHotelTarifa) {
            $monedaCosto = $opcionHotelTarifa->opcionHotel?->moneda ?? 'PEN';
            $costoSnapshot = (float) $opcionHotelTarifa->precio_costo;
            $precioVentaSnapshot = (float) $opcionHotelTarifa->precio_venta;
        } else {
            $monedaCosto = $validado['moneda_costo'];
            // costo_snapshot es NOT NULL: sin tarifa de la que derivarlo, 0 es el valor centinela (igual
            // que en los ítems manuales).
            $costoSnapshot = $validado['costo_snapshot'] ?? 0;
            $precioVentaSnapshot = (float) $validado['precio_venta_snapshot'];
        }

        $tratamientoTributario = match (true) {
            (bool) $tarifa => ConfiguracionAgencia::completarTratamientoTributario($tarifa->tip_afe_igv, $tarifa->destino_tributario),
            (bool) $opcionHotelTarifa => ConfiguracionAgencia::completarTratamientoTributario($opcionHotelTarifa->tip_afe_igv, $opcionHotelTarifa->destino_tributario),
            default => ConfiguracionAgencia::completarTratamientoTributario($validado['tip_afe_igv'] ?? null, $validado['destino_tributario'] ?? null),
        };

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'alternativa_destino_id' => $alternativa->destinoIdODefault($validado['alternativa_destino_id'] ?? null),
            'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
            'proveedor_tarifa_id' => $tarifa?->id,
            'opcion_hotel_tarifa_id' => $opcionHotelTarifa?->id,
            'grupo_opcion_id' => $validado['grupo_opcion_id'] ?? null,
            // Un hotel ad-hoc siempre es por habitación, sin importar lo que vino en el request.
            'modo_precio' => $opcionHotelTarifa ? 'tarifa_fija' : $validado['modo_precio'],
            'cantidad' => $cantidad,
            'pax_incluidos' => $paxIncluidos,
            'moneda_costo' => $monedaCosto,
            'costo_snapshot' => $costoSnapshot,
            'precio_venta_snapshot' => $precioVentaSnapshot,
            'precio_convertido' => $this->convertir($precioVentaSnapshot, $monedaCosto, $alternativa),
            'dia_referencial' => $validado['dia_referencial'] ?? null,
            'tip_afe_igv' => $tratamientoTributario['tip_afe_igv'],
            'destino_tributario' => $tratamientoTributario['destino_tributario'],
        ]);

        $this->cerrar($alternativa);

        return $item;
    }

    // Costo y venta extra de N camas adicionales para una tarifa_fija de proveedor. Usa la edad REAL del
    // pasajero (cotizacion_pasajeros.edad), nunca tipo_pax: un pasajero de 10 años puede ser 'adulto' por el
    // umbral general de la agencia y aun así caer dentro del tramo de cama adicional de ESTE proveedor.
    private function camaAdicional(Alternativa $alternativa, ProveedorTarifa $tarifa, ?array $paxIncluidos, int $camasAdicionales): array
    {
        $alojamiento = $tarifa->proveedorServicio?->proveedor?->alojamientoDetalle;

        if (! $alojamiento) {
            throw new AltaItemException('Este proveedor no tiene configurado el detalle de alojamiento (tramo de edad para cama adicional).');
        }

        $tienePaxEnTramo = CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)
            ->when(
                ! empty($paxIncluidos),
                fn ($query) => $query->whereIn('id', $paxIncluidos),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->where('edad', '>', $alojamiento->edad_max_infante_gratis)
            ->where('edad', '<=', $alojamiento->edad_max_nino_cama_adicional)
            ->exists();

        if (! $tienePaxEnTramo) {
            throw new AltaItemException('Cama adicional solo aplica si pax_incluidos tiene al menos un pasajero dentro del tramo de edad de cama adicional de este proveedor.');
        }

        if ($tarifa->precio_costo_cama_adicional === null || $tarifa->precio_venta_cama_adicional === null) {
            throw new AltaItemException('Esta tarifa no tiene precio de cama adicional configurado.');
        }

        return [
            'costo' => $camasAdicionales * (float) $tarifa->precio_costo_cama_adicional,
            'venta' => $camasAdicionales * (float) $tarifa->precio_venta_cama_adicional,
        ];
    }
}
