<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// Ocurrencia anual concreta de una temporada (central, catálogo padre
// Sesión 1) — plan-modulo-proveedores.md §2.6. CentralConnection
// obligatorio, mismo criterio que Temporada/ProveedorTipo/TipoComprobante.
// FK real a Temporada (misma base central).
class TemporadaOcurrencia extends Model
{
    use CentralConnection;

    protected $table = 'temporada_ocurrencias';

    protected $fillable = [
        'temporada_id',
        'anio',
        'fecha_desde',
        'fecha_hasta',
    ];

    protected $casts = [
        'fecha_desde' => 'date',
        'fecha_hasta' => 'date',
    ];

    public function temporada()
    {
        return $this->belongsTo(Temporada::class, 'temporada_id');
    }
}
