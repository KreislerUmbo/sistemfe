<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// T-136 — cuentas bancarias de la empresa, del sistema base: las usan los pagos a proveedor y, en
// Agencia, el PDF de cotización.
class CuentaBancaria extends Model
{
    protected $table = 'cuentas_bancarias';

    public const MONEDAS = ['PEN', 'USD'];

    protected $fillable = [
        'banco',
        'titular',
        'numero_cuenta',
        'cci',
        'alias',
        'activo',
        'orden',
        'moneda',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
