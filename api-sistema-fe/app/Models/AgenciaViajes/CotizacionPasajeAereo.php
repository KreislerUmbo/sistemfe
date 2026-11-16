<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Pasaje aéreo vendido SUELTO — plan-modulo-cotizaciones-reservas.md §2.5.
// 1-a-1 con AlternativaItem (unique en alternativa_item_id). Tenant (sin
// CentralConnection). aerolinea es texto libre a propósito, sin FK (ver
// comentario de la migración).
//
// tip_afe_igv es la afectación del pasaje: la factura la aplica al precio
// completo (tarifa + cargos + fee), porque la agencia revende el boleto
// completo (D-AGE-035). Una corrección a mano en el reserva_item
// (fecha_override_tributario) gana sobre este valor al facturar (T-091).
class CotizacionPasajeAereo extends Model
{
    protected $table = 'cotizacion_pasaje_aereo';

    protected $fillable = [
        'alternativa_item_id',
        'aerolinea',
        'itinerario',
        'moneda',
        'tarifa_base_adulto',
        'tarifa_base_nino',
        'tarifa_base_infante',
        'cargos',
        'tua_incluida_en_tarifa',
        'fee_agencia_monto',
        'montos_por_pasajero',
        'tip_afe_igv',
        'fecha_cotizado',
        'tarifa_valida_hasta',
        'costo_total',
        'precio_venta_total',
    ];

    protected $casts = [
        'tarifa_base_adulto' => 'decimal:2',
        'tarifa_base_nino' => 'decimal:2',
        'tarifa_base_infante' => 'decimal:2',
        'cargos' => 'array',
        'tua_incluida_en_tarifa' => 'boolean',
        'fee_agencia_monto' => 'decimal:2',
        'montos_por_pasajero' => 'boolean',
        'fecha_cotizado' => 'datetime',
        'tarifa_valida_hasta' => 'datetime',
        'costo_total' => 'decimal:2',
        'precio_venta_total' => 'decimal:2',
    ];

    public function alternativaItem()
    {
        return $this->belongsTo(AlternativaItem::class, 'alternativa_item_id');
    }
}
