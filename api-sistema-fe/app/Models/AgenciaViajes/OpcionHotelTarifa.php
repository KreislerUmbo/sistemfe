<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// plan-modulo-cotizaciones-reservas.md §2.4. Tenant (sin CentralConnection).
// opcion_hotel_id es FK real dentro de la misma DB tenant (misma sesión),
// así que lleva belongsTo.
//
// proveedor_tarifa_id: RETROFIT Sesión 11k, Fix 9 — si está seteado, el
// hotel ya es un proveedor registrado y esta fila reusa su tarifa real en
// vez de un precio tipeado a mano (plan-modulo-cotizaciones-reservas.md
// §3.7). precio_costo/precio_venta siguen persistiéndose igual (snapshot
// legible si el día de mañana se borra la tarifa real — nullOnDelete ya
// cubre la FK, pero el número visible no debería desaparecer) — los
// accessors de abajo solo pisan el valor guardado MIENTRAS la relación
// siga viva, no reemplazan tenerlo guardado.
class OpcionHotelTarifa extends Model
{
    protected $table = 'opciones_hotel_tarifas';

    protected $fillable = [
        'opcion_hotel_id',
        'tipo_habitacion',
        'precio_costo',
        'precio_venta',
        'proveedor_tarifa_id',
        'precio_costo_cama_adicional',
        'precio_venta_cama_adicional',
        // Sesión M3 (Ronda 6/P16) — nullable, resuelto contra el default de
        // agencia al crear si no viene explícito (ver
        // OpcionHotelController::store()). Copiado en snapshot al
        // materializar un AlternativaItem, mismo criterio que costo_snapshot.
        'tip_afe_igv',
        'destino_tributario',
    ];

    protected $casts = [
        'precio_costo' => 'decimal:2',
        'precio_venta' => 'decimal:2',
        'precio_costo_cama_adicional' => 'decimal:2',
        'precio_venta_cama_adicional' => 'decimal:2',
    ];

    public function opcionHotel()
    {
        return $this->belongsTo(OpcionHotel::class, 'opcion_hotel_id');
    }

    public function proveedorTarifa()
    {
        return $this->belongsTo(ProveedorTarifa::class, 'proveedor_tarifa_id');
    }

    // Precio "en vivo": mientras haya una tarifa real vinculada, el precio
    // mostrado es el de esa tarifa, no el valor guardado en esta fila —
    // mismo espíritu que el precio de combo (ComboExplosionService), nunca
    // se muestra un valor stale si hay una fuente de verdad viva.
    public function getPrecioCostoAttribute($value)
    {
        return $this->proveedorTarifa?->precio_costo ?? $value;
    }

    public function getPrecioVentaAttribute($value)
    {
        return $this->proveedorTarifa?->precio_venta_adulto ?? $value;
    }
}
