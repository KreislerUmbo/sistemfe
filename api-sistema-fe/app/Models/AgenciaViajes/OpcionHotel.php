<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// plan-modulo-cotizaciones-reservas.md §2.4. Tenant (sin CentralConnection).
// opcion_mayorista_id NO lleva relación todavía — la tabla opcion_mayorista
// es Sesión 7, no existe aún, sin FK real en la migración (ver TODO.md).
// paquete_plantilla_id SÍ lleva belongsTo desde Sesión 6 (FK real cerrada
// vía retrofit, ver 2026_07_27_200300_add_paquete_plantilla_foreign_to_opciones_hotel_table.php).
// proveedor_id también lleva belongsTo (FK real, Sesión 3).
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

    public function paquetePlantilla()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'paquete_plantilla_id');
    }

    public function opcionesHotelTarifas()
    {
        return $this->hasMany(OpcionHotelTarifa::class, 'opcion_hotel_id');
    }
}
