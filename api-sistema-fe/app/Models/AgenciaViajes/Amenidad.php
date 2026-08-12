<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// Catálogo central de amenidades de proveedor (wifi, parqueo, piscina...) —
// mismo criterio que ProveedorTipo. CentralConnection obligatorio: sin el
// trait, este modelo terminaría consultando la BD del tenant activo por
// error.
class Amenidad extends Model
{
    use CentralConnection;

    protected $table = 'amenidades';

    protected $fillable = [
        'nombre',
        'icono',
        'slug',
    ];
}
