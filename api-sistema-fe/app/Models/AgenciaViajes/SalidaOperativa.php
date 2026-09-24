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

    // Bug real (auditoría 2026-09-22): los 3 puntos donde un reserva_item
    // deja de pertenecer a una salida (reprogramar(), detachReservaItem(),
    // ReservaItemController::destroy()) nunca revisaban si la salida quedó
    // sin ningún ítem — quedaba visible para siempre en el tablero de
    // despacho con 0 pasajeros. El comentario original en
    // ReservaController::reprogramar() ("la SalidaOperativa vieja NUNCA se
    // borra acá, puede seguir compartida por otras reservas") sigue siendo
    // la razón correcta de NO borrar siempre — este helper solo cubre el
    // caso real de que NO quedó compartida por nadie.
    public static function eliminarSiQuedoVacia(?int $salidaId): void
    {
        if ($salidaId === null) {
            return;
        }

        $salida = self::withCount('reservaItems')->find($salidaId);
        if ($salida && $salida->reserva_items_count === 0) {
            $salida->delete();
        }
    }
}
