<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Alternativa de cotización (combinación completa de paquete) —
// plan-modulo-cotizaciones-reservas.md §3.1/§3.2. Tenant (sin
// CentralConnection). cotizacion_id lleva belongsTo real (FK dentro de la
// misma DB tenant).
class Alternativa extends Model
{
    protected $table = 'alternativas';

    protected $fillable = [
        'cotizacion_id',
        'nombre',
        'estado',
        'moneda_cotizacion',
        'tipo_cambio_aplicado',
        'tipo_cambio_origen',
        'fecha_envio',
        'fecha_vencimiento',
        'descuento_global_pct',
        'total',
    ];

    protected $casts = [
        'tipo_cambio_aplicado' => 'decimal:4',
        'fecha_envio' => 'datetime',
        'fecha_vencimiento' => 'datetime',
        'descuento_global_pct' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function cotizacion()
    {
        return $this->belongsTo(Cotizacion::class, 'cotizacion_id');
    }

    public function items()
    {
        return $this->hasMany(AlternativaItem::class, 'alternativa_id');
    }
}
