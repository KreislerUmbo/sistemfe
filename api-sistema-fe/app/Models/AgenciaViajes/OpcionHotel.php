<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// plan-modulo-cotizaciones-reservas.md §2.4. Tenant (sin CentralConnection).
// opcion_mayorista_id/paquete_plantilla_id NO llevan relación todavía —
// las tablas opcion_mayorista (Sesión 7) y paquetes_plantilla (Sesión 6)
// no existen aún, sin FK real en la migración (ver TODO.md). proveedor_id
// sí lleva belongsTo (FK real, Sesión 3).
class OpcionHotel extends Model
{
    protected $table = 'opciones_hotel';

    protected $fillable = [
        'opcion_mayorista_id',
        'paquete_plantilla_id',
        'proveedor_id',
        'nombre_hotel',
        'categoria_estrellas',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function opcionesHotelTarifas()
    {
        return $this->hasMany(OpcionHotelTarifa::class, 'opcion_hotel_id');
    }
}
