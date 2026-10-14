<?php

namespace App\Models\AgenciaViajes;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

// Historial de envíos por correo (plan-modulo-envio-correo-cotizacion.md §5.3).
// Toda solicitud deja una fila, también las que fallan.
class CotizacionEnvio extends Model
{
    public const ESTADO_ENVIADO = 'enviado';
    public const ESTADO_FALLIDO = 'fallido';

    public const TIPO_COTIZACION = 'cotizacion';
    public const TIPO_PRUEBA = 'prueba';

    public const MODO_DOMINIO_AGENCIA = 'dominio_agencia';
    public const MODO_PLATAFORMA = 'plataforma';

    protected $table = 'cotizacion_envios';

    protected $fillable = [
        'cotizacion_id',
        'alternativa_id',
        'usuario_id',
        'tipo',
        'para',
        'cc',
        'bcc',
        'remitente',
        'modo_remitente',
        'asunto',
        'cuerpo',
        'adjuntos',
        'estado',
        'error',
        'message_id',
    ];

    protected $casts = [
        'para' => 'array',
        'cc' => 'array',
        'bcc' => 'array',
        'adjuntos' => 'array',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function alternativa()
    {
        return $this->belongsTo(Alternativa::class, 'alternativa_id');
    }
}
