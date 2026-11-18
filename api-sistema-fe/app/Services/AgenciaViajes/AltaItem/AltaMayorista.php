<?php

namespace App\Services\AgenciaViajes\AltaItem;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;

// T-095 — origen_tipo=mayorista. El precio sale de EXACTAMENTE una de dos fuentes:
// opcion_hotel_tarifa_id (la matriz de habitación) u opcion_mayorista_opcional_id (un tour opcional
// —San Blas, Taboga, Colón— que el cliente eligió agregar de verdad al lienzo). Las dos comparten la
// validación de la OpcionMayorista (de esta alternativa y 'elegida') y el tratamiento tributario;
// solo difiere de dónde salen costo y venta. Es un paquete internacional: siempre tarifa_fija.
class AltaMayorista extends AltaItemBase
{
    public function crear(Alternativa $alternativa, array $datos): AlternativaItem
    {
        $validado = $this->validarReglas($datos, [
            'opcion_mayorista_id' => 'required|integer|exists:opcion_mayorista,id',
            'opcion_hotel_tarifa_id' => 'required_without:opcion_mayorista_opcional_id|nullable|integer|exists:opciones_hotel_tarifas,id',
            'opcion_mayorista_opcional_id' => 'required_without:opcion_hotel_tarifa_id|nullable|integer|exists:opcion_mayorista_opcionales,id',
            'cantidad' => 'nullable|integer|min:1',
            'pax_incluidos' => 'nullable|array',
            'dia_referencial' => 'nullable|integer|min:1',
            'tip_afe_igv' => 'nullable|string|in:10,20,30',
            'destino_tributario' => 'nullable|string|in:amazonia,nacional,extranjero',
            'alternativa_destino_id' => ['nullable', 'integer', $alternativa->reglaDestinoId()],
            // El mismo uuid agrupa las filas de un "Agregar N opciones como grupo" (AlternativaItem::agruparPorGrupoOpcion()).
            'grupo_opcion_id' => 'nullable|uuid',
        ]);

        $opcion = OpcionMayorista::findOrFail($validado['opcion_mayorista_id']);

        if ($opcion->alternativa_id !== $alternativa->id) {
            throw new AltaItemException('La opción de mayorista no pertenece a esta alternativa.');
        }
        if ($opcion->estado !== 'elegida') {
            throw new AltaItemException('Marcá la opción de mayorista como elegida antes de agregarla al ítem.');
        }

        $tarifaHotel = null;
        $opcional = null;

        if (isset($validado['opcion_hotel_tarifa_id'])) {
            $tarifaHotel = OpcionHotelTarifa::with('opcionHotel')->findOrFail($validado['opcion_hotel_tarifa_id']);
            if ($tarifaHotel->opcionHotel?->opcion_mayorista_id !== $opcion->id) {
                throw new AltaItemException('Esa tarifa de habitación no pertenece a la opción de mayorista indicada.');
            }
        } else {
            $opcional = OpcionMayoristaOpcional::findOrFail($validado['opcion_mayorista_opcional_id']);
            if ($opcional->opcion_mayorista_id !== $opcion->id) {
                throw new AltaItemException('Ese opcional no pertenece a la opción de mayorista indicada.');
            }
        }

        $tratamientoTributario = ConfiguracionAgencia::completarTratamientoTributario($validado['tip_afe_igv'] ?? null, $validado['destino_tributario'] ?? null);

        // Un opcional no tiene columna de costo, solo el precio que se cobra al cliente: costo 0 es
        // honesto (no inventa un costo que nadie registró).
        $precioVenta = $tarifaHotel?->precio_venta ?? $opcional->precio_por_persona;
        $costo = $tarifaHotel?->precio_costo ?? 0;
        $moneda = $opcional?->moneda ?? $opcion->moneda;

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'alternativa_destino_id' => $alternativa->destinoIdODefault($validado['alternativa_destino_id'] ?? null),
            'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA,
            'opcion_mayorista_id' => $opcion->id,
            // Qué fila de la matriz de habitación fijó el precio: la trazabilidad hacia la reserva, el
            // reporte operativo y el PDF la necesitan.
            'opcion_hotel_tarifa_id' => $tarifaHotel?->id,
            'opcion_mayorista_opcional_id' => $opcional?->id,
            'grupo_opcion_id' => $validado['grupo_opcion_id'] ?? null,
            'modo_precio' => 'tarifa_fija',
            'cantidad' => $validado['cantidad'] ?? 1,
            'pax_incluidos' => $validado['pax_incluidos'] ?? null,
            'moneda_costo' => $moneda,
            'costo_snapshot' => $costo,
            'precio_venta_snapshot' => $precioVenta,
            'precio_convertido' => $this->convertir((float) $precioVenta, $moneda, $alternativa),
            'dia_referencial' => $validado['dia_referencial'] ?? null,
            'tip_afe_igv' => $tratamientoTributario['tip_afe_igv'],
            'destino_tributario' => $tratamientoTributario['destino_tributario'],
        ]);

        $this->cerrar($alternativa);

        return $item;
    }
}
