<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Catálogo simple de guías turísticos — plan-modulo-cotizaciones-reservas.md
// §5.3. Solo estos 4 campos; guia_tarifas (costo/margen por guía × destino ×
// modalidad) es Sesión 5, no vive acá. Tenant (sin CentralConnection).
class Guia extends Model
{
    protected $table = 'guias';

    protected $fillable = [
        'nombre',
        'documento',
        'telefono',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
