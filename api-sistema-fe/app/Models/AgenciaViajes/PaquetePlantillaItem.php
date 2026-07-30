<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Ítems del "Incluye" de un paquete_plantilla — plan-modulo-cotizaciones-reservas.md
// §3.7 ("cada ítem es un destino_servicio + proveedor_tarifa real, no
// texto libre"). Nombre de tabla decidido en Sesión 6, ver comentario de
// la migración. Tenant (sin CentralConnection). Las 4 FK son reales
// dentro de la misma DB tenant, así que llevan belongsTo.
//
// paquete_plantilla_hijo_id: Sesión 11b4 — mutuamente excluyente con
// proveedor_tarifa_id/guia_tarifa_id (exactamente uno de los tres), validado
// en ComboValidationService, no acá ni en la migración.
class PaquetePlantillaItem extends Model
{
    protected $table = 'paquete_plantilla_items';

    protected $fillable = [
        'paquete_plantilla_id',
        'proveedor_tarifa_id',
        'guia_tarifa_id',
        'paquete_plantilla_hijo_id',
        'orden',
    ];

    protected $casts = [
        'orden' => 'integer',
    ];

    public function paquetePlantilla()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'paquete_plantilla_id');
    }

    public function proveedorTarifa()
    {
        return $this->belongsTo(ProveedorTarifa::class, 'proveedor_tarifa_id');
    }

    public function guiaTarifa()
    {
        return $this->belongsTo(GuiaTarifa::class, 'guia_tarifa_id');
    }

    public function paquetePlantillaHijo()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'paquete_plantilla_hijo_id');
    }
}
