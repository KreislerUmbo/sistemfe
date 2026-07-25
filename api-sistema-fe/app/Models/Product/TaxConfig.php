<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class TaxConfig extends Model
{
    use CentralConnection;

    protected $table = 'tax_configs';

    protected $fillable = [
        'nombre',
        'codigo_sunat',
        'tipo',
        'tasa_porcentaje',
        'fecha_inicio_vigencia',
        'fecha_fin_vigencia',
        'ubigeos_aplicables',
        'es_activo'
    ];

    protected $casts = [
        'ubigeos_aplicables' => 'array',
        'fecha_inicio_vigencia' => 'date',
        'fecha_fin_vigencia' => 'date',
    ];
}
