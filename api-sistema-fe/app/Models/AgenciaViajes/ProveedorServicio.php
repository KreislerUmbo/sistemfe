<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Tabla puente entre proveedores y destino_servicio —
// plan-modulo-proveedores.md §2.6. Tenant (sin CentralConnection). Ambas
// FK son reales dentro de la misma DB tenant (Proveedor/DestinoServicio,
// Sesión 3), así que sí llevan belongsTo.
class ProveedorServicio extends Model
{
    protected $table = 'proveedor_servicios';

    protected $fillable = [
        'proveedor_id',
        'destino_servicio_id',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function destinoServicio()
    {
        return $this->belongsTo(DestinoServicio::class, 'destino_servicio_id');
    }

    public function proveedorTarifas()
    {
        return $this->hasMany(ProveedorTarifa::class, 'proveedor_servicio_id');
    }
}
