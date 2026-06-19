<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'client_id',
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

    public function items()//relacion con los items del carrito
    {
        return $this->hasMany(OrderItem::class);
    }
        public function client() //relacion con el cliente
    {
        return $this->belongsTo(\App\Models\Client\Client::class);
    }
}
