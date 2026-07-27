<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// Catálogo central, exclusivo del vertical agencia de viajes (columna
// `giro`) — plan-modulo-proveedores.md §2.6. CentralConnection obligatorio:
// sin el trait, este modelo terminaría consultando la BD del tenant activo
// por error (bug recurrente ya documentado en
// arquitectura-multitenant-backend.md), mismo criterio que TipoComprobante.
class ProveedorTipo extends Model
{
    use CentralConnection;

    protected $table = 'proveedor_tipos';

    protected $fillable = [
        'nombre',
        'slug',
        'giro',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
