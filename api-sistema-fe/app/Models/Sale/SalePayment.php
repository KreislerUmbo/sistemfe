<?php

namespace App\Models\Sale;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalePayment extends Model
{
    use SoftDeletes;
    protected $fillable = [
        "sale_id",
        "method_payment",
        "amount",
        "date_payment",
        "comments", //COLLATE en postgresql significa que se ignora la mayuscula en la busqueda
        "nro_operacion",
        "moneda_pago",
        "monto_pagado",
        "tipo_cambio",
        "origen", // T-012: 'cobro' | 'traspaso'
        "traspasado_de_sale_id",
    ];

    protected $casts = [
        "amount" => "float",
        "monto_pagado" => "float",
        "tipo_cambio" => "float",
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, "sale_id");
    }
}
