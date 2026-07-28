<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Comparación entre mayoristas dentro de una alternativa —
// plan-modulo-cotizaciones-reservas.md §2.4. Tenant (sin CentralConnection).
// alternativa_id/proveedor_id/salida_mayorista_id llevan belongsTo real (FK
// dentro de la misma DB tenant).
class OpcionMayorista extends Model
{
    protected $table = 'opcion_mayorista';

    protected $fillable = [
        'alternativa_id',
        'proveedor_id',
        'salida_mayorista_id',
        'moneda',
        'incluye',
        'notas',
        'vuelo_aerolinea',
        'vuelo_detalle',
        'estado',
    ];

    public function alternativa()
    {
        return $this->belongsTo(Alternativa::class, 'alternativa_id');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function salidaMayorista()
    {
        return $this->belongsTo(SalidaMayorista::class, 'salida_mayorista_id');
    }

    public function opcionales()
    {
        return $this->hasMany(OpcionMayoristaOpcional::class, 'opcion_mayorista_id');
    }

    public function opcionesHotel()
    {
        return $this->hasMany(OpcionHotel::class, 'opcion_mayorista_id');
    }

    public function alternativaItems()
    {
        return $this->hasMany(AlternativaItem::class, 'opcion_mayorista_id');
    }
}
