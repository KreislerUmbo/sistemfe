<?php

namespace App\Models\AgenciaViajes;

use App\Models\Sale\SaleDetail;
use Illuminate\Database\Eloquent\Model;

// Tabla puente — qué reserva_items cubre cada línea de factura (sale_detail)
// — plan-modulo-cotizaciones-reservas.md §6.2. Tenant (sin
// CentralConnection). reserva_item_id lleva belongsTo real (FK dentro de la
// misma DB tenant); sale_detail_id apunta al core (App\Models\Sale\SaleDetail,
// tabla 'sale_details').
//
// El reporte operativo (Sesión 10) y los itinerarios leen de reserva_items
// directamente, NUNCA de esta tabla — no usarla como atajo de reportes.
class SaleDetailItem extends Model
{
    protected $table = 'sale_detail_items';

    protected $fillable = [
        'sale_detail_id',
        'reserva_item_id',
    ];

    public function saleDetail()
    {
        return $this->belongsTo(SaleDetail::class, 'sale_detail_id');
    }

    public function reservaItem()
    {
        return $this->belongsTo(ReservaItem::class, 'reserva_item_id');
    }
}
