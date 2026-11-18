<?php

namespace App\Services\AgenciaViajes\AltaItem;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;

// T-095 — origen_tipo=manual: guarda lo que escribió el vendedor, sin cálculo. costo y venta son
// obligatorios, cantidad multiplica (AlternativaItem::getTotalAttribute()), pax_incluidos se propaga
// a reserva_item_pasajero al aceptar y proveedor_sugerido_manual es dato interno (nunca visible al
// cliente) para prellenar el proveedor al promover el ítem.
class AltaManual extends AltaItemBase
{
    public function crear(Alternativa $alternativa, array $datos): AlternativaItem
    {
        $validado = $this->validar($datos, [
            'descripcion_manual' => 'required|string|max:500',
            'proveedor_sugerido_manual' => 'nullable|string|max:250',
            'costo_snapshot' => 'required|numeric|min:0',
            'precio_venta_snapshot' => 'required|numeric|min:0',
            'moneda_costo' => 'required|in:PEN,USD',
            'cantidad' => 'required|integer|min:1',
            'pax_incluidos' => 'nullable|array',
            'pax_incluidos.*' => 'integer|exists:cotizacion_pasajeros,id',
            'dia_referencial' => 'nullable|integer|min:1',
            'tip_afe_igv' => 'nullable|string|in:10,20,30',
            'destino_tributario' => 'nullable|string|in:amazonia,nacional,extranjero',
            'alternativa_destino_id' => ['nullable', 'integer', $alternativa->reglaDestinoId()],
        ]);

        $tratamientoTributario = ConfiguracionAgencia::completarTratamientoTributario($validado['tip_afe_igv'] ?? null, $validado['destino_tributario'] ?? null);

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'alternativa_destino_id' => $alternativa->destinoIdODefault($validado['alternativa_destino_id'] ?? null),
            'origen_tipo' => AlternativaItem::ORIGEN_MANUAL,
            'descripcion_manual' => $validado['descripcion_manual'],
            'proveedor_sugerido_manual' => $validado['proveedor_sugerido_manual'] ?? null,
            'modo_precio' => 'tarifa_fija',
            'cantidad' => $validado['cantidad'],
            'pax_incluidos' => $validado['pax_incluidos'] ?? null,
            'costo_snapshot' => $validado['costo_snapshot'],
            'moneda_costo' => $validado['moneda_costo'],
            'precio_venta_snapshot' => $validado['precio_venta_snapshot'],
            'precio_convertido' => $this->convertir((float) $validado['precio_venta_snapshot'], $validado['moneda_costo'], $alternativa),
            'dia_referencial' => $validado['dia_referencial'] ?? null,
            'tip_afe_igv' => $tratamientoTributario['tip_afe_igv'],
            'destino_tributario' => $tratamientoTributario['destino_tributario'],
        ]);

        $this->cerrar($alternativa);

        return $item;
    }
}
