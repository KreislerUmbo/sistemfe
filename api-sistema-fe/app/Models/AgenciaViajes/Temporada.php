<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// Catálogo central, exclusivo del vertical agencia de viajes (columna
// `giro`) — plan-modulo-proveedores.md §2.6. CentralConnection obligatorio,
// mismo criterio que ProveedorTipo/TipoComprobante (ver ese comentario).
class Temporada extends Model
{
    use CentralConnection;

    protected $table = 'temporadas';

    protected $fillable = [
        'nombre',
        'tipo',
        'giro',
    ];

    public function temporadaOcurrencias()
    {
        return $this->hasMany(TemporadaOcurrencia::class, 'temporada_id');
    }
}
