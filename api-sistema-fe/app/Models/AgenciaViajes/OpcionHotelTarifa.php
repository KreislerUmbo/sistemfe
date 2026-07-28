<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// plan-modulo-cotizaciones-reservas.md §2.4. Tenant (sin CentralConnection).
// opcion_hotel_id es FK real dentro de la misma DB tenant (misma sesión),
// así que lleva belongsTo.
class OpcionHotelTarifa extends Model
{
    protected $table = 'opciones_hotel_tarifas';

    protected $fillable = [
        'opcion_hotel_id',
        'tipo_habitacion',
        'precio_costo',
        'precio_venta',
    ];

    protected $casts = [
        'precio_costo' => 'decimal:2',
        'precio_venta' => 'decimal:2',
    ];

    public function opcionHotel()
    {
        return $this->belongsTo(OpcionHotel::class, 'opcion_hotel_id');
    }
}
