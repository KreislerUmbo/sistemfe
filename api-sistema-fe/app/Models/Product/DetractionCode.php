<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class DetractionCode extends Model
{
    use CentralConnection;

    protected $table = 'detraction_codes';

    protected $fillable = [
        'codigo',
        'nombre',
        'tasa_porcentaje',
        'tipo',
        'estado'
    ];
}
