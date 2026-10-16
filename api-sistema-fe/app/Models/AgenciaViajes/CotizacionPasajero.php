<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Conteo de pasajeros por cotización, con edad real — plan-modulo-cotizaciones-reservas.md
// §3.1. Tenant (sin CentralConnection). cotizacion_id lleva belongsTo real
// (FK dentro de la misma DB tenant).
class CotizacionPasajero extends Model
{
    protected $table = 'cotizacion_pasajeros';

    public const RESIDENCIA_NACIONAL = 'nacional';
    public const RESIDENCIA_EXTRANJERO = 'extranjero';

    protected $fillable = [
        'cotizacion_id',
        'tipo_pax',
        'edad',
        // Fila 13, fase 1b (§3.3): solo cambia el precio del tour, no el IGV.
        // No es la nacionalidad.
        'residencia',
    ];

    protected $casts = [
        'edad' => 'integer',
    ];

    public function cotizacion()
    {
        return $this->belongsTo(Cotizacion::class, 'cotizacion_id');
    }
}
