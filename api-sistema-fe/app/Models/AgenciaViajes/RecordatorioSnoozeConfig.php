<?php

namespace App\Models\AgenciaViajes;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

// Preferencia de posponer/omitir un tipo de recordatorio, por usuario —
// plan-modulo-cotizaciones-reservas.md §8bis. Tenant (sin CentralConnection).
// usuario_id/tipo_id llevan belongsTo real.
//
// omitir=true no es absoluto: si algún Recordatorio puntual de este tipo
// tiene forzado=true, esa excepción se resuelve en aplicación (Sesión 11),
// no acá.
class RecordatorioSnoozeConfig extends Model
{
    protected $table = 'recordatorio_snooze_config';

    protected $fillable = [
        'usuario_id',
        'tipo_id',
        'snooze_minutos',
        'omitir',
    ];

    protected $casts = [
        'omitir' => 'boolean',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function tipo()
    {
        return $this->belongsTo(TipoRecordatorio::class, 'tipo_id');
    }
}
