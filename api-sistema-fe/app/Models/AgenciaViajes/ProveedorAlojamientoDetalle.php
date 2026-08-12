<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Datos específicos de un proveedor tipo Alojamiento — 1:1 con proveedores.
// Tenant (sin CentralConnection). proveedor_id lleva belongsTo real (FK
// dentro de la misma DB tenant).
class ProveedorAlojamientoDetalle extends Model
{
    protected $table = 'proveedor_alojamiento_detalle';

    protected $fillable = [
        'proveedor_id',
        'hora_checkin',
        'hora_checkout',
        'edad_max_infante_gratis',
        'edad_max_nino_cama_adicional',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }
}
