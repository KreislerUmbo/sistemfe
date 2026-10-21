<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Agrupa reserva_items de distintas reservas que comparten
// tour_origen_id + fecha y son modalidad='compartido' — ver comentario
// completo de diseño en la migración de creación de esta tabla.
//
// Fila 13, fase 2a (plan-tours-venta-simple-costo-real.md §3.6, D9): agrupa
// también las líneas de tour del modo nuevo. Tour 'compartido' = una salida
// por tour y fecha; tour 'privado' = una salida por reserva
// (privada_reserva_id). Estados: programada → confirmada → realizada, o
// cancelada.
class SalidaOperativa extends Model
{
    protected $table = 'salidas_operativas';

    public const ESTADO_PROGRAMADA = 'programada';
    public const ESTADO_CONFIRMADA = 'confirmada';
    public const ESTADO_REALIZADA = 'realizada';
    public const ESTADO_CANCELADA = 'cancelada';

    // Salidas que todavía se operan (se ven en el Inicio, reciben reservas).
    public const ESTADOS_EN_CURSO = [self::ESTADO_PROGRAMADA, self::ESTADO_CONFIRMADA];

    protected $fillable = [
        'tour_origen_id',
        'privada_reserva_id',
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

    // Solo en una salida privada: la reserva dueña.
    public function reservaPrivada()
    {
        return $this->belongsTo(Reserva::class, 'privada_reserva_id');
    }

    public function esPrivada(): bool
    {
        return $this->privada_reserva_id !== null;
    }

    public function scopeEnCurso($query)
    {
        return $query->whereIn('estado', self::ESTADOS_EN_CURSO);
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
