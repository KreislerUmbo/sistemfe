<?php

namespace App\Models\Client;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

// T-050 (CRM C1) — un contacto con el cliente: envío de una cotización, llamada, WhatsApp,
// visita o nota. El historial vive por cliente; la cotización o reserva es una referencia
// opcional sin FK (son tablas del vertical Agencia).
class CrmActividad extends Model
{
    protected $table = 'crm_actividades';

    public const TIPO_ENVIO = 'envio';
    public const TIPO_LLAMADA = 'llamada';
    public const TIPO_WHATSAPP = 'whatsapp';
    public const TIPO_VISITA = 'visita';
    public const TIPO_NOTA = 'nota';
    public const TIPO_ESTADO = 'estado';

    // Lo que el vendedor anota a mano ("Anotar contacto"); envio y estado los escribe el sistema.
    public const TIPOS_MANUALES = [self::TIPO_LLAMADA, self::TIPO_WHATSAPP, self::TIPO_VISITA, self::TIPO_NOTA];

    public const CANAL_CORREO = 'correo';
    public const CANAL_WHATSAPP = 'whatsapp';
    public const CANAL_OTRO = 'otro';

    public const REFERENCIA_COTIZACION = 'cotizacion';
    public const REFERENCIA_RESERVA = 'reserva';

    public const NOTA_MAX = 1000;

    protected $fillable = [
        'cliente_id',
        'referencia_tipo',
        'referencia_id',
        'alternativa_id',
        'tipo',
        'canal',
        'nota',
        'usuario_id',
    ];

    public function cliente()
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function scopeDeCotizacion($query, int $cotizacionId)
    {
        return $query->where('referencia_tipo', self::REFERENCIA_COTIZACION)->where('referencia_id', $cotizacionId);
    }
}
