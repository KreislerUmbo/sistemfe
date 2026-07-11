<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;

class DetractionCode extends Model
{
    protected $table = 'detraction_codes';

    protected $fillable = [
        'codigo',
        'nombre',
        'tasa_porcentaje',
        'tipo',
        'estado'
    ];
}
