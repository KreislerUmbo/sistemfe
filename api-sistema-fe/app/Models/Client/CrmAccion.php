<?php

namespace App\Models\Client;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

// T-051 (CRM C2) — una próxima acción con el cliente: qué hacer, qué día y quién. Pendiente
// mientras hecha_en es null. Como CrmActividad, vive por cliente y la cotización o reserva es una
// referencia opcional sin FK (tablas del vertical Agencia).
class CrmAccion extends Model
{
    protected $table = 'crm_acciones';

    public const TIPO_LLAMAR = 'llamar';
    public const TIPO_WHATSAPP = 'whatsapp';
    public const TIPO_ENVIAR_COTIZACION = 'enviar_cotizacion';
    public const TIPO_COBRAR_SALDO = 'cobrar_saldo';
    public const TIPO_SALUDAR = 'saludar';
    public const TIPO_OTRO = 'otro';

    public const TIPOS = [
        self::TIPO_LLAMAR => 'Llamar',
        self::TIPO_WHATSAPP => 'Escribir por WhatsApp',
        self::TIPO_ENVIAR_COTIZACION => 'Enviar cotización',
        self::TIPO_COBRAR_SALDO => 'Cobrar saldo',
        self::TIPO_SALUDAR => 'Saludar',
        self::TIPO_OTRO => 'Otro',
    ];

    public const DESCRIPCION_MAX = 500;

    protected $fillable = [
        'cliente_id',
        'referencia_tipo',
        'referencia_id',
        'tipo',
        'descripcion',
        'fecha',
        'responsable_id',
        'hecha_en',
        'hecha_por',
        'creada_por',
    ];

    protected $casts = [
        'fecha' => 'date:Y-m-d',
        'hecha_en' => 'datetime',
    ];

    public function cliente()
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }

    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function scopePendientes($query)
    {
        return $query->whereNull('hecha_en');
    }

    public function scopeDeReferencia($query, string $tipo, int $id)
    {
        return $query->where('referencia_tipo', $tipo)->where('referencia_id', $id);
    }

    public function estaPendiente(): bool
    {
        return $this->hecha_en === null;
    }

    public function etiquetaTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
