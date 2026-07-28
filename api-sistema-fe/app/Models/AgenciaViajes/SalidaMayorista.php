<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Catálogo de paquetes armados en fechas fijas por un mayorista —
// plan-modulo-cotizaciones-reservas.md §2.4. Independiente de cualquier
// cotización puntual. Tenant (sin CentralConnection). proveedor_id lleva
// belongsTo real (FK dentro de la misma DB tenant, Sesión 3).
class SalidaMayorista extends Model
{
    protected $table = 'salidas_mayorista';

    protected $fillable = [
        'proveedor_id',
        'nombre',
        'fecha_salida',
        'fecha_retorno',
        'cupo_total',
        'cupo_ocupado',
        'precio_costo',
        'moneda',
        'incluye',
        'estado',
    ];

    protected $casts = [
        'fecha_salida' => 'date',
        'fecha_retorno' => 'date',
        'precio_costo' => 'decimal:2',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function opcionesMayorista()
    {
        return $this->hasMany(OpcionMayorista::class, 'salida_mayorista_id');
    }
}
