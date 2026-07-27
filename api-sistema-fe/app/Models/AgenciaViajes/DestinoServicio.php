<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Tabla puente entre destinos_atractivos y servicios —
// plan-modulo-tours-catalogo.md §4. Tenant (sin CentralConnection).
// Ambas FK son reales dentro de la misma DB tenant (a diferencia de las
// referencias cross-DB a proveedor_tipos central), así que sí llevan
// belongsTo.
class DestinoServicio extends Model
{
    protected $table = 'destino_servicio';

    protected $fillable = [
        'destino_atractivo_id',
        'servicio_id',
    ];

    public function destinoAtractivo()
    {
        return $this->belongsTo(DestinoAtractivo::class, 'destino_atractivo_id');
    }

    public function servicio()
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }
}
