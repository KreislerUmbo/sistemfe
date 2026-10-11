<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Models\Client\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Link "Mi crédito" de un cliente (Fase 5b). El token va cifrado; se busca por su hash. */
class CreditoEnlaceCliente extends Model
{
    protected $table = 'credito_enlaces_cliente';

    protected $fillable = [
        'cliente_id', 'token', 'token_hash', 'version', 'intentos_fallidos', 'bloqueado_hasta',
        'accesos', 'ultimo_acceso_en', 'creado_por', 'regenerado_en',
    ];

    protected $hidden = ['token', 'token_hash'];

    /** Los mismos defaults que la tabla: sin esto, un link recién creado tenía accesos/versión null. */
    protected $attributes = ['version' => 1, 'intentos_fallidos' => 0, 'accesos' => 0];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'bloqueado_hasta' => 'datetime',
            'ultimo_acceso_en' => 'datetime',
            'regenerado_en' => 'datetime',
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function bloqueado(): bool
    {
        return $this->bloqueado_hasta !== null && $this->bloqueado_hasta->isFuture();
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }
}
