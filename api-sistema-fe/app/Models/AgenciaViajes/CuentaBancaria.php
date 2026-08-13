<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

class CuentaBancaria extends Model
{
    protected $table = 'cuentas_bancarias';

    protected $fillable = [
        'banco',
        'titular',
        'numero_cuenta',
        'cci',
        'alias',
        'activo',
        'orden',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
