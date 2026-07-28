<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Documento de identidad del pasajero (DNI/pasaporte/etc.) —
// plan-modulo-cotizaciones-reservas.md §6.5. Tenant (sin CentralConnection).
// pasajero_catalogo_id lleva belongsTo real.
//
// archivo: path en disco privado — el endpoint autenticado que lo sirve es
// Sesión 11, no modelado acá (ver comentario de la migración).
class PasajeroDocumento extends Model
{
    protected $table = 'pasajero_documentos';

    protected $fillable = [
        'pasajero_catalogo_id',
        'tipo_documento',
        'numero_documento',
        'fecha_vencimiento',
        'archivo',
        'fecha_registro',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
        'fecha_registro' => 'date',
    ];

    public function pasajeroCatalogo()
    {
        return $this->belongsTo(PasajeroCatalogo::class, 'pasajero_catalogo_id');
    }
}
