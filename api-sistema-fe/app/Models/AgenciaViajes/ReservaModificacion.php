<?php

namespace App\Models\AgenciaViajes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Una modificación de reserva (T-002; R-AGE-01, R-AGE-02, D-AGE-030). Une la reserva con la
// cotización de modificación (origen = 'modificacion') y las dos versiones: la vendida (la
// alternativa vigente al abrir, que no se edita) y la nueva (su copia, que el vendedor edita en
// el cotizador). Tenant (sin CentralConnection). Se conserva siempre: descartar o aplicar solo
// cambia el estado.
class ReservaModificacion extends Model
{
    public const ESTADO_ABIERTA = 'abierta';

    public const ESTADO_APLICADA = 'aplicada';

    public const ESTADO_DESCARTADA = 'descartada';

    protected $table = 'reserva_modificaciones';

    protected $fillable = [
        'reserva_id',
        'cotizacion_id',
        'alternativa_vendida_id',
        'alternativa_nueva_id',
        'estado',
        'creado_por',
        'aplicado_por',
        'aplicado_en',
        'descartado_por',
        'descartado_en',
        'diferencia_aplicada',
    ];

    protected $casts = [
        'aplicado_en' => 'datetime',
        'descartado_en' => 'datetime',
        'diferencia_aplicada' => 'array',
    ];

    public function scopeAbierta(Builder $query): Builder
    {
        return $query->where('estado', self::ESTADO_ABIERTA);
    }

    public function estaAbierta(): bool
    {
        return $this->estado === self::ESTADO_ABIERTA;
    }

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function cotizacion()
    {
        return $this->belongsTo(Cotizacion::class, 'cotizacion_id');
    }

    public function alternativaVendida()
    {
        return $this->belongsTo(Alternativa::class, 'alternativa_vendida_id');
    }

    public function alternativaNueva()
    {
        return $this->belongsTo(Alternativa::class, 'alternativa_nueva_id');
    }

    public function creador()
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
