<?php

namespace App\Models\Sale;

use App\Models\Cash\Branch;
use Illuminate\Database\Eloquent\Model;

// Serie operativa por sucursal — tenant. tipo_comprobante_codigo es una
// referencia cross-boundary a la tabla central tipos_comprobante (sin FK real
// de Postgres, mismo caso que products.codigo_detraccion → detraction_codes),
// así que NO se modela como relación Eloquent belongsTo aquí — se valida
// explícitamente donde haga falta (SerieComprobanteController, SerieComprobanteService).
class SerieComprobante extends Model
{
    protected $table = 'serie_comprobantes';

    protected $fillable = [
        'branch_id',
        'tipo_comprobante_codigo',
        'moneda',
        'serie',
        'correlativo_actual',
        'correlativo_inicial',
        'fecha_inicio',
        'activo',
    ];

    protected $casts = [
        'activo'      => 'boolean',
        'fecha_inicio' => 'date',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
