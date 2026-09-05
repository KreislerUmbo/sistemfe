<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Vínculo entre una OpcionMayorista y un tour real (PaquetePlantilla) incluido en
// el paquete — ver migración create_opcion_mayorista_tours_table. 'orden' es el
// "Día" del tour dentro de la secuencia de tours incluidos de esta opción, leído
// por AlternativaController::itinerarioAlternativa() para encadenar el offset de
// días igual que ya hace con los tours de un combo Local/Nacional. Tenant (sin
// CentralConnection). Ambas FK son reales dentro de la misma DB tenant.
class OpcionMayoristaTour extends Model
{
    protected $table = 'opcion_mayorista_tours';

    protected $fillable = [
        'opcion_mayorista_id',
        'paquete_plantilla_id',
        'orden',
    ];

    protected $casts = [
        'orden' => 'integer',
    ];

    public function opcionMayorista()
    {
        return $this->belongsTo(OpcionMayorista::class, 'opcion_mayorista_id');
    }

    public function paquetePlantilla()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'paquete_plantilla_id');
    }
}
