<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Vínculo entre una OpcionMayorista y un tour incluido en el paquete — ver
// migración create_opcion_mayorista_tours_table. 'orden' es el "Día" del
// tour dentro de la secuencia de tours incluidos de esta opción, leído por
// AlternativaPdfService::itinerarioAlternativa() para encadenar el offset
// de días igual que ya hace con los tours de un combo Local/Nacional.
// Tenant (sin CentralConnection).
//
// Guardrail (18-sep-2026, migración add_adhoc_a_opcion_mayorista_tours) —
// paquete_plantilla_id es nullable: una fila SIN él es un tour ad-hoc
// (nombre/descripcion propios acá, sin PaquetePlantilla/TourItinerarioItem
// detrás) — logística pura de ESTE itinerario (Arribo/Retorno/traslado),
// nunca un producto de catálogo reutilizable. Mismo criterio que
// OpcionHotel.proveedor_id (hotel ad-hoc): las 2 vías son mutuamente
// excluyentes en la práctica (un tour real no llena nombre/descripcion acá,
// los toma de paquetePlantilla()), pero no hay una constraint de base de
// datos que lo fuerce — la validación vive en
// OpcionMayoristaController::tours().
class OpcionMayoristaTour extends Model
{
    protected $table = 'opcion_mayorista_tours';

    protected $fillable = [
        'opcion_mayorista_id',
        'paquete_plantilla_id',
        'nombre',
        'descripcion',
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
