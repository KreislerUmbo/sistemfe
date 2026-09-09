<?php

namespace App\Models\TipoCambio;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class TipoCambioSunat extends Model
{
    use CentralConnection;

    protected $table = 'tipo_cambio_sunat';

    protected $fillable = [
        'fecha',
        'compra',
        'venta',
        'fuente',
        'consultado_en',
    ];

    protected $casts = [
        'fecha' => 'date',
        'consultado_en' => 'datetime',
    ];
}
