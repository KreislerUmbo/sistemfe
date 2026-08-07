<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// plan-modulo-cotizaciones-reservas.md §2.4. Tenant (sin CentralConnection).
// Las 3 FK llevan belongsTo real: paquete_plantilla_id desde Sesión 6
// (2026_07_27_200300_add_paquete_plantilla_foreign_to_opciones_hotel_table.php),
// opcion_mayorista_id desde Sesión 7b
// (2026_07_28_100400_add_opcion_mayorista_foreign_to_opciones_hotel_table.php
// — cierra la FK diferida desde Sesión 5), proveedor_id desde Sesión 3.
class OpcionHotel extends Model
{
    protected $table = 'opciones_hotel';

    protected $fillable = [
        'opcion_mayorista_id',
        'paquete_plantilla_id',
        'proveedor_id',
        'nombre_hotel',
        'categoria_estrellas',
        'moneda',
        'edad_max_infante_gratis',
        'edad_max_nino_cama_adicional',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function paquetePlantilla()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'paquete_plantilla_id');
    }

    public function opcionMayorista()
    {
        return $this->belongsTo(OpcionMayorista::class, 'opcion_mayorista_id');
    }

    public function opcionesHotelTarifas()
    {
        return $this->hasMany(OpcionHotelTarifa::class, 'opcion_hotel_id');
    }
}
