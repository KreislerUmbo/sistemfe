<?php

namespace App\Models\AgenciaViajes;

use App\Models\Sale\Sale;
use Illuminate\Database\Eloquent\Model;

// Tabla puente entre reserva y Sale — NO un campo simple sale_id, una
// reserva puede terminar con más de una venta (§4.3 "documento adicional",
// §4.4 pagos por pasajero con varios responsables). Tenant (sin
// CentralConnection). reserva_id lleva belongsTo real; sale_id apunta al
// core (App\Models\Sale\Sale, tabla 'sales').
class ReservaVenta extends Model
{
    protected $table = 'reserva_ventas';

    protected $fillable = [
        'reserva_id',
        'sale_id',
        'reserva_item_ids',
        'reserva_pasajero_ids',
    ];

    protected $casts = [
        'reserva_item_ids' => 'array',
        'reserva_pasajero_ids' => 'array',
    ];

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
