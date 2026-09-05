<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// Catálogo central de afiliaciones de turismo (Mincetur/Apavit/PromPerú) —
// plan-mejora-pdf-cotizacion-cliente.md §4.3. CentralConnection obligatorio,
// mismo criterio que ProveedorTipo: sin el trait, este modelo consultaría
// por error la BD del tenant activo.
class AfiliacionTurismo extends Model
{
    use CentralConnection;

    protected $table = 'afiliaciones_turismo';

    protected $fillable = [
        'codigo',
        'nombre',
        'logo_path',
    ];
}
