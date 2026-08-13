<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Agrupa reserva_items de distintas reservas que comparten
// tour_origen_id + fecha y son modalidad='compartido' — ver comentario
// completo de diseño en la migración de creación de esta tabla.
class SalidaOperativa extends Model
{
    protected $table = 'salidas_operativas';

    protected $fillable = [
        'tour_origen_id',
        'fecha',
        'hora',
        'guia_id',
        'cupo_maximo',
        'vehiculo_descripcion',
        'estado',
        'notas',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function tourOrigen()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'tour_origen_id');
    }

    public function guia()
    {
        return $this->belongsTo(Guia::class, 'guia_id');
    }

    public function reservaItems()
    {
        return $this->hasMany(ReservaItem::class, 'salida_operativa_id');
    }
}
