<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Tabla puente: qué pasajero específico va en qué ítem/actividad —
// plan-modulo-cotizaciones-reservas.md §4. Tenant (sin CentralConnection).
// Ambas FK son reales dentro de la misma DB tenant, así que llevan
// belongsTo.
class ReservaItemPasajero extends Model
{
    protected $table = 'reserva_item_pasajero';

    protected $fillable = [
        'reserva_item_id',
        'reserva_pasajero_id',
    ];

    public function reservaItem()
    {
        return $this->belongsTo(ReservaItem::class, 'reserva_item_id');
    }

    public function reservaPasajero()
    {
        return $this->belongsTo(ReservaPasajero::class, 'reserva_pasajero_id');
    }
}
