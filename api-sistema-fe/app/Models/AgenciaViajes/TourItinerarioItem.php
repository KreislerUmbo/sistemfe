<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Itinerario a nivel de plantilla de tour, por día relativo —
// plan-modulo-cotizaciones-reservas.md §5.1. Tenant (sin CentralConnection).
// tour_id/destino_atractivo_id llevan belongsTo reales (FK dentro de la
// misma DB tenant). destino_atractivo_id puede ser null (pasos de
// traslado/cierre sin atractivo específico).
class TourItinerarioItem extends Model
{
    protected $table = 'tour_itinerario_items';

    protected $fillable = [
        'tour_id',
        'dia_relativo',
        'hora',
        'orden',
        'destino_atractivo_id',
        'descripcion',
    ];

    public function tour()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'tour_id');
    }

    public function destinoAtractivo()
    {
        return $this->belongsTo(DestinoAtractivo::class, 'destino_atractivo_id');
    }
}
