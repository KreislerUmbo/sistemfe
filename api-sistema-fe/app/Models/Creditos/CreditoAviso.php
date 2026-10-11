<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un aviso enviado o intentado (Fase 5): uno por usuario, tipo, fecha de negocio y canal. */
class CreditoAviso extends Model
{
    public const TIPO_COBRANZA_DIA = 'cobranza_dia';

    public const CANAL_CORREO = 'correo';
    public const CANAL_PUSH = 'push';

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_ENVIADO = 'enviado';
    public const ESTADO_FALLIDO = 'fallido';
    public const ESTADO_OMITIDO = 'omitido';

    /** Un fallido se reintenta en las siguientes corridas del día hasta este número de intentos. */
    public const MAXIMO_INTENTOS = 3;

    protected $table = 'credito_avisos';

    protected $fillable = [
        'user_id', 'tipo', 'fecha', 'canal', 'estado', 'intentos', 'detalle',
        'cantidad_cobros', 'monto_esperado', 'enviado_en', 'abierto_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'monto_esperado' => 'decimal:2',
            'enviado_en' => 'datetime',
            'abierto_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
