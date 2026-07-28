<?php

namespace App\Models\AgenciaViajes;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

// Mensaje flotante (toast) en-app — plan-modulo-cotizaciones-reservas.md
// §8bis. Tenant (sin CentralConnection). tipo_id/usuario_id/creado_por
// llevan belongsTo real.
//
// entidad_id NO lleva relación: es genuinamente polimórfico sin FK de
// Postgres (entidad_tipo determina la tabla — reserva/cotizaciones/clients
// del core/pago_proveedor — ver comentario de la migración
// 2026_07_28_160200_create_recordatorios_table.php), no un forward-reference
// de sesión futura como las FK diferidas que este vertical fue cerrando
// (ver TODO.md Sesión 9c). Resolver la entidad real según entidad_tipo es
// lógica de aplicación, Sesión 11.
class Recordatorio extends Model
{
    protected $table = 'recordatorios';

    protected $fillable = [
        'tipo_id',
        'entidad_tipo',
        'entidad_id',
        'titulo',
        'mensaje',
        'fecha_disparo',
        'usuario_id',
        'rol_destino',
        'creado_por',
        'forzado',
        'estado',
    ];

    protected $casts = [
        'fecha_disparo' => 'datetime',
        'forzado' => 'boolean',
    ];

    public function tipo()
    {
        return $this->belongsTo(TipoRecordatorio::class, 'tipo_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function creadoPor()
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
