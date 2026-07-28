<?php

namespace App\Models\AgenciaViajes;

use App\Models\Advance\Advance;
use Illuminate\Database\Eloquent\Model;

// Etiqueta un Advance (core) contra una reserva específica, antes de que
// exista el Sale final — plan-modulo-cotizaciones-reservas.md §4.5. Tenant
// (sin CentralConnection). reserva_id lleva belongsTo real; advance_id
// apunta al core (App\Models\Advance\Advance, tabla 'advances').
class ReservaAnticipo extends Model
{
    protected $table = 'reserva_anticipos';

    protected $fillable = [
        'reserva_id',
        'advance_id',
        'monto_asignado',
        'fecha_asignacion',
    ];

    protected $casts = [
        'monto_asignado' => 'decimal:2',
        'fecha_asignacion' => 'date',
    ];

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function advance()
    {
        return $this->belongsTo(Advance::class, 'advance_id');
    }
}
