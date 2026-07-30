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

    // Sesión 11c — usada al aceptar una alternativa para saber si hay que
    // mover cupo_ocupado en salidas_mayorista (§4/§4.2). No hay CHECK ni
    // índice único que garantice como máximo una 'elegida' por
    // alternativa — la garantía la da OpcionMayoristaController::elegir()
    // (desmarca la anterior antes de marcar la nueva).
    public function opcionMayoristaElegida()
    {
        return $this->hasOne(OpcionMayorista::class, 'alternativa_id')->where('estado', 'elegida');
    }
}
