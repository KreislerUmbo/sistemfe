<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Copiado de la alternativa aceptada, con fecha/hora concretas —
// plan-modulo-cotizaciones-reservas.md §4. Tenant (sin CentralConnection).
// reserva_id/alternativa_item_id/guia_id/proveedor_tarifa_id llevan
// belongsTo real (FK dentro de la misma DB tenant). Costo/precio se leen
// vía alternativaItem(), no se duplican columnas acá.
//
// proveedor_tarifa_id: retrofit Sesión 11c (ver migración
// 2026_07_30_100000_retrofit_reserva_para_sesion_11c.php) — "quién opera"
// se confirma cerca de la fecha, reasignable en cualquier momento, igual
// que guia_id.
class ReservaItem extends Model
{
    protected $table = 'reserva_items';

    protected $fillable = [
        'reserva_id',
        'alternativa_item_id',
        'fecha',
        'hora',
        'guia_id',
        'proveedor_tarifa_id',
        'tour_origen_id',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function alternativaItem()
    {
        return $this->belongsTo(AlternativaItem::class, 'alternativa_item_id');
    }

    public function guia()
    {
        return $this->belongsTo(Guia::class, 'guia_id');
    }

    public function proveedorTarifa()
    {
        return $this->belongsTo(ProveedorTarifa::class, 'proveedor_tarifa_id');
    }

    // Sesión 11b4 — mismo propósito que AlternativaItem::tourOrigen(),
    // copiado al crear la reserva.
    public function tourOrigen()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'tour_origen_id');
    }

    public function pasajeros()
    {
        return $this->belongsToMany(ReservaPasajero::class, 'reserva_item_pasajero', 'reserva_item_id', 'reserva_pasajero_id');
    }
}
