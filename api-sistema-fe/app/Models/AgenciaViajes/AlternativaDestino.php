<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Sesión 12b — registro liviano por destino de un viaje (auditoria-
// arquitectonica-agencia-viajes.md §7). Existe ANTES que cualquier
// AlternativaItem/OpcionMayorista — 12c/12d los cuelgan de acá. Sin
// moneda/tipo de cambio propios a propósito, eso se queda en Alternativa.
class AlternativaDestino extends Model
{
    protected $table = 'alternativa_destinos';

    protected $fillable = [
        'alternativa_id',
        'destino_atractivo_id',
        'destino_texto',
        'orden',
        'fecha_inicio',
        'fecha_fin',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    public function alternativa()
    {
        return $this->belongsTo(Alternativa::class, 'alternativa_id');
    }

    public function destinoAtractivo()
    {
        return $this->belongsTo(DestinoAtractivo::class, 'destino_atractivo_id');
    }
}
