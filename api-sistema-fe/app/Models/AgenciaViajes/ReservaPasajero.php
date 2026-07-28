<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Pasajero con datos completos (a diferencia de CotizacionPasajero, que
// solo cuenta por tipo/edad) — plan-modulo-cotizaciones-reservas.md §4.
// Tenant (sin CentralConnection). reserva_id/pasajero_catalogo_id llevan
// belongsTo real — pasajero_catalogo_id cerrado vía retrofit en Sesión 9c
// (2026_07_28_150200_add_pasajero_catalogo_foreign_to_reserva_pasajeros_table.php),
// última FK diferida del vertical.
class ReservaPasajero extends Model
{
    protected $table = 'reserva_pasajeros';

    protected $fillable = [
        'reserva_id',
        'nombre',
        'documento',
        'nacionalidad',
        'alimentacion_especial',
        'discapacidad',
        'vuelo_aerolinea_ida',
        'vuelo_hora_ida',
        'vuelo_aerolinea_vuelta',
        'vuelo_hora_vuelta',
        'pasajero_catalogo_id',
    ];

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function pasajeroCatalogo()
    {
        return $this->belongsTo(PasajeroCatalogo::class, 'pasajero_catalogo_id');
    }

    public function reservaItems()
    {
        return $this->belongsToMany(ReservaItem::class, 'reserva_item_pasajero', 'reserva_pasajero_id', 'reserva_item_id');
    }
}
