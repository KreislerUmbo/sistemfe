<?php

namespace App\Models\AgenciaViajes;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

// Histórico de tipo de cambio — plan-modulo-cotizaciones-reservas.md §3.4.
// Nunca se sobrescribe, se inserta una fila nueva cada vez. Tenant (sin
// CentralConnection). registrado_por lleva belongsTo real a users (misma
// DB tenant).
class TipoCambioAgencia extends Model
{
    protected $table = 'tipo_cambio_agencia';

    protected $fillable = [
        'fecha',
        'origen',
        'valor',
        'registrado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
        'valor' => 'decimal:4',
    ];

    public function registradoPor()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
