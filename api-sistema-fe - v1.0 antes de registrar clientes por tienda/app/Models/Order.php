<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'nombres',
        'apellidos',
        'email',
        'telefono',
        'dni',
        'direccion',
        'departamento',
        'provincia',
        'distrito',
        'referencia',
        'entrega_tipo',
        'comprobante_tipo',
        'ruc',
        'razon_social',
        'metodo_pago',
        'cupon',
        'subtotal',
        'descuento',
        'total',
        'estado'
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
