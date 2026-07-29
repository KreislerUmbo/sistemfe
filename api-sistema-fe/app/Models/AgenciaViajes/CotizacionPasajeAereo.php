<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Pasaje aéreo vendido SUELTO — plan-modulo-cotizaciones-reservas.md §2.5.
// 1-a-1 con AlternativaItem (unique en alternativa_item_id). Tenant (sin
// CentralConnection). aerolinea es texto libre a propósito, sin FK (ver
// comentario de la migración).
//
// tip_afe_igv aplica SOLO sobre fee_agencia_monto — tarifa_base_* + cargos
// son pass-through del costo de terceros (aerolínea/aeropuerto), no
// ingreso propio de la agencia. Fácil de malinterpretar como aplicando al
// costo_total/precio_venta_total completo: NO es así.
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
        'tip_afe_igv',
        'fecha_cotizado',
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
        'fecha_cotizado' => 'datetime',
        'costo_total' => 'decimal:2',
        'precio_venta_total' => 'decimal:2',
    ];

    public function alternativaItem()
    {
        return $this->belongsTo(AlternativaItem::class, 'alternativa_item_id');
    }
}
