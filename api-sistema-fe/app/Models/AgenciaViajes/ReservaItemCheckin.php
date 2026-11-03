<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Check-in del reporte operativo (T-041, H30): una fila = ese pasajero hizo
// check-in en ese servicio. Tabla propia, sin relación con
// reserva_item_pasajero (la asignación pasajero↔servicio que usa la
// facturación): marcar o desmarcar el check-in nunca cambia la asignación.
// Tenant (sin CentralConnection).
class ReservaItemCheckin extends Model
{
    protected $table = 'reserva_item_checkins';

    protected $fillable = [
        'reserva_item_id',
        'reserva_pasajero_id',
        'checkin_hora',
    ];

    protected $casts = [
        'checkin_hora' => 'datetime',
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
