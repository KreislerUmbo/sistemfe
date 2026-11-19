<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

// T-065 — otra dirección de un cliente (p. ej. una sucursal). El comprobante copia solo `direccion`
// (una línea, igual que la fiscal); `activo` filtra lo que se puede elegir, no lo ya emitido.
class ClientAddress extends Model
{
    protected $table = 'client_addresses';

    protected $fillable = [
        'client_id', 'nombre', 'direccion', 'referencia',
        'region', 'provincia', 'distrito',
        'ubigeo_region', 'ubigeo_provincia', 'ubigeo_distrito',
        'activo',
    ];

    protected $casts = ['activo' => 'boolean'];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
